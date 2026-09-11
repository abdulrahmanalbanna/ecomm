-- ============================================================================
-- Migration 005 : Products, Product-Scoped Attributes, Options & Variants
-- Project       : commerce-single-vendor
-- Depends on    : 004_categories.sql (categories)
--
-- FINAL RECONCILED VERSION
-- Supersedes the earlier draft of this file and migration_005_products_moddify.sql
-- (now deleted). This is the single production migration for the catalog schema.
-- Run it once after the prerequisite categories migration.
--
-- PostgreSQL compatibility: 13–17.
--
-- Reconciliation notes:
--   * Based on the superset draft (moddify): product-scoped attribute
--     configuration, dimension/tag/media/flat-object CHECK validators,
--     deterministic lock helpers, hard-delete blocking, deferred sellability.
--   * Duplicates removed vs 018_functions_triggers.sql:
--       - fn_products_search_vector_set + trg_products_00_search_vector
--         (018's trg_products_search_vector owns search_vector maintenance)
--       - updated_at triggers on products / product_variants
--         (018's trg_products_updated_at / trg_product_variants_updated_at own those)
--     updated_at triggers are KEPT here only for the three attribute tables
--     that 018 does not cover.
--   * Optimizations applied:
--       - Unknown-key detection in fn_validate_variant_attributes is a single
--         LEFT JOIN ... IS NULL probe instead of two COUNT scans.
--       - Redundant per-row product lock removed from
--         fn_validate_variant_attributes: trg_product_variants_00_parent_lock
--         always fires first (same-event triggers run in name order).
--
-- Authoritative requiredness rule:
--   product_attribute_definitions.is_required is the only requiredness value
--   used by database validation. The schema intentionally does not define a
--   global attribute_definitions.is_required column.
--
-- Concurrency protocol:
--   1) Lock affected product rows in ascending product_id order.
--   2) Lock affected attributes with deterministic transaction-level advisory
--      locks in ascending attribute_id order.
--   3) Read or mutate metadata/JSONB only after those locks are held.
--
-- Custom SQLSTATE codes:
--   P0006 = invalid variant attribute key/value
--   P0007 = invalid product status transition
--   P0008 = published product has no sellable variant
--   P0009 = hard DELETE/TRUNCATE is blocked
--   P0010 = immutable/in-use attribute catalog change
--   P0011 = soft-deleted row cannot be restored
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 0) Preconditions
-- ---------------------------------------------------------------------------
DO $$
BEGIN
    IF to_regclass('categories') IS NULL THEN
        RAISE EXCEPTION
            'Migration 005 requires categories from 004_categories.sql';
    END IF;

    -- Keep extension ownership outside this migration. The UUID support must be
    -- installed by the extensions migration or by the deployment administrator.
    IF to_regprocedure('gen_random_uuid()') IS NULL THEN
        RAISE EXCEPTION
            'Migration 005 requires gen_random_uuid(); install UUID support before running it';
    END IF;
END;
$$;

-- ---------------------------------------------------------------------------
-- 1) Immutable CHECK validators
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_chk_media_array(p_media JSONB)
RETURNS BOOLEAN
LANGUAGE plpgsql
IMMUTABLE
STRICT
AS $$
DECLARE
    v_element  JSONB;
    v_type     TEXT;
    v_position NUMERIC;
BEGIN
    IF jsonb_typeof(p_media) <> 'array' THEN
        RETURN FALSE;
    END IF;

    FOR v_element IN
        SELECT value FROM jsonb_array_elements(p_media)
    LOOP
        IF jsonb_typeof(v_element) <> 'object' THEN
            RETURN FALSE;
        END IF;

        IF jsonb_typeof(v_element -> 'url') <> 'string'
           OR NULLIF(btrim(v_element ->> 'url'), '') IS NULL THEN
            RETURN FALSE;
        END IF;

        IF v_element ? 'alt'
           AND jsonb_typeof(v_element -> 'alt') <> 'string' THEN
            RETURN FALSE;
        END IF;

        IF v_element ? 'type' THEN
            IF jsonb_typeof(v_element -> 'type') <> 'string' THEN
                RETURN FALSE;
            END IF;

            v_type := v_element ->> 'type';
            IF v_type NOT IN ('image', 'video', 'audio', 'file') THEN
                RETURN FALSE;
            END IF;
        END IF;

        IF v_element ? 'position' THEN
            IF jsonb_typeof(v_element -> 'position') <> 'number' THEN
                RETURN FALSE;
            END IF;

            v_position := (v_element ->> 'position')::NUMERIC;
            IF v_position < 0 OR trunc(v_position) <> v_position THEN
                RETURN FALSE;
            END IF;
        END IF;
    END LOOP;

    RETURN TRUE;
END;
$$;

CREATE OR REPLACE FUNCTION fn_chk_flat_json_object(p_data JSONB)
RETURNS BOOLEAN
LANGUAGE plpgsql
IMMUTABLE
STRICT
AS $$
DECLARE
    v_value JSONB;
BEGIN
    IF jsonb_typeof(p_data) <> 'object' THEN
        RETURN FALSE;
    END IF;

    FOR v_value IN
        SELECT value FROM jsonb_each(p_data)
    LOOP
        IF jsonb_typeof(v_value) IN ('object', 'array') THEN
            RETURN FALSE;
        END IF;
    END LOOP;

    RETURN TRUE;
END;
$$;

CREATE OR REPLACE FUNCTION fn_chk_dimensions(p_dimensions JSONB)
RETURNS BOOLEAN
LANGUAGE plpgsql
IMMUTABLE
AS $$
DECLARE
    v_key  TEXT;
    v_unit TEXT;
BEGIN
    IF p_dimensions IS NULL THEN
        RETURN TRUE;
    END IF;

    IF jsonb_typeof(p_dimensions) <> 'object' THEN
        RETURN FALSE;
    END IF;

    IF NOT (p_dimensions ? 'length')
       OR NOT (p_dimensions ? 'width')
       OR NOT (p_dimensions ? 'height')
       OR NOT (p_dimensions ? 'unit') THEN
        RETURN FALSE;
    END IF;

    FOREACH v_key IN ARRAY ARRAY['length', 'width', 'height']
    LOOP
        IF jsonb_typeof(p_dimensions -> v_key) <> 'number' THEN
            RETURN FALSE;
        END IF;

        IF (p_dimensions ->> v_key)::NUMERIC <= 0 THEN
            RETURN FALSE;
        END IF;
    END LOOP;

    IF jsonb_typeof(p_dimensions -> 'unit') <> 'string' THEN
        RETURN FALSE;
    END IF;

    v_unit := btrim(p_dimensions ->> 'unit');
    IF v_unit NOT IN ('mm', 'cm', 'm', 'in', 'ft') THEN
        RETURN FALSE;
    END IF;

    RETURN TRUE;
END;
$$;

CREATE OR REPLACE FUNCTION fn_chk_tags(p_tags TEXT[])
RETURNS BOOLEAN
LANGUAGE plpgsql
IMMUTABLE
STRICT
AS $$
DECLARE
    v_tag      TEXT;
    v_total    BIGINT;
    v_distinct BIGINT;
BEGIN
    -- STRICT: called with NULL input returns NULL, which the NOT NULL DEFAULT
    -- on products.tags ensures never happens in practice. STRICT is added for
    -- consistency with fn_chk_media_array and fn_chk_flat_json_object.

    SELECT count(*), count(DISTINCT tag)
    INTO v_total, v_distinct
    FROM unnest(p_tags) AS t(tag);

    IF v_total <> v_distinct THEN
        RETURN FALSE;
    END IF;

    FOREACH v_tag IN ARRAY p_tags
    LOOP
        IF v_tag IS NULL
           OR NULLIF(btrim(v_tag), '') IS NULL
           OR v_tag <> btrim(v_tag)
           OR v_tag <> lower(v_tag) THEN
            RETURN FALSE;
        END IF;
    END LOOP;

    RETURN TRUE;
END;
$$;

COMMENT ON FUNCTION fn_chk_media_array(JSONB) IS
    'Validates media objects, non-empty URLs, allowed types, and non-negative integer positions.';
COMMENT ON FUNCTION fn_chk_flat_json_object(JSONB) IS
    'Validates a flat JSONB object with scalar values only.';
COMMENT ON FUNCTION fn_chk_dimensions(JSONB) IS
    'Validates positive dimensions and units mm, cm, m, in, or ft.';
COMMENT ON FUNCTION fn_chk_tags(TEXT[]) IS
    'Validates lowercase, non-empty, trimmed, duplicate-free tags.';

-- ---------------------------------------------------------------------------
-- 2) PRODUCTS
-- ---------------------------------------------------------------------------

CREATE TABLE products (
    id                BIGSERIAL,
    public_id         UUID         NOT NULL DEFAULT gen_random_uuid(),
    category_id       BIGINT       NOT NULL,
    slug              VARCHAR(300) NOT NULL,
    name              VARCHAR(300) NOT NULL,
    description       TEXT,
    short_description TEXT,
    brand             VARCHAR(150),
    tags              TEXT[]       NOT NULL DEFAULT ARRAY[]::TEXT[],
    media             JSONB        NOT NULL DEFAULT '[]'::JSONB,
    specifications    JSONB        NOT NULL DEFAULT '{}'::JSONB,
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    is_featured       BOOLEAN      NOT NULL DEFAULT FALSE,
    status            VARCHAR(30)  NOT NULL DEFAULT 'draft',
    seo_title         VARCHAR(300),
    seo_description   TEXT,
    search_vector     TSVECTOR     NOT NULL DEFAULT ''::TSVECTOR,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ,

    CONSTRAINT products_pkey PRIMARY KEY (id),
    CONSTRAINT products_public_id_key UNIQUE (public_id),
    CONSTRAINT products_category_id_fkey
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT,
    CONSTRAINT products_slug_key UNIQUE (slug),
    CONSTRAINT products_slug_format_check
        CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT products_tags_check
        CHECK (fn_chk_tags(tags)),
    CONSTRAINT products_media_structure_check
        CHECK (fn_chk_media_array(media)),
    CONSTRAINT products_specifications_structure_check
        CHECK (fn_chk_flat_json_object(specifications)),
    CONSTRAINT products_status_check
        CHECK (status IN ('draft', 'published', 'archived')),
    CONSTRAINT products_published_active_check
        CHECK (status <> 'published' OR is_active = TRUE),
    CONSTRAINT products_deleted_state_check
        CHECK (deleted_at IS NULL OR is_active = FALSE),
    CONSTRAINT products_deleted_published_check
        CHECK (deleted_at IS NULL OR status <> 'published')
);

COMMENT ON TABLE products IS
    'Product master catalog. Product-specific variant attributes are configured separately.';
COMMENT ON COLUMN products.public_id IS
    'External UUID for API and customer-facing URLs; internal BIGSERIAL id remains private.';
COMMENT ON COLUMN products.category_id IS
    'Exactly one category per product. Category deletion is restricted.';
COMMENT ON COLUMN products.tags IS
    'Trimmed lowercase duplicate-free TEXT[] tags.';
COMMENT ON COLUMN products.media IS
    'JSONB media array with non-empty URL and validated optional fields.';
COMMENT ON COLUMN products.specifications IS
    'Flat JSONB key/value specifications; nested objects and arrays are rejected.';
COMMENT ON COLUMN products.search_vector IS
    'Maintained by trg_products_search_vector in 018_functions_triggers.sql using the simple configuration.';
COMMENT ON COLUMN products.deleted_at IS
    'Soft-delete timestamp. A published product must be archived before deletion.';
COMMENT ON CONSTRAINT products_slug_format_check ON products IS
    'Lowercase alphanumeric slug with internal hyphens only.';
COMMENT ON CONSTRAINT products_published_active_check ON products IS
    'Published products must remain active and customer-visible.';
COMMENT ON CONSTRAINT products_deleted_state_check ON products IS
    'A soft-deleted product must be inactive.';
COMMENT ON CONSTRAINT products_deleted_published_check ON products IS
    'A soft-deleted product cannot remain published.';

-- ---------------------------------------------------------------------------
-- 3) ATTRIBUTE DEFINITIONS
-- ---------------------------------------------------------------------------

CREATE TABLE attribute_definitions (
    id            BIGSERIAL,
    name          VARCHAR(100) NOT NULL,
    display_name  VARCHAR(200) NOT NULL,
    type          VARCHAR(30)  NOT NULL,
    unit          VARCHAR(30),
    is_filterable BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT attribute_definitions_pkey PRIMARY KEY (id),
    CONSTRAINT attribute_definitions_name_key UNIQUE (name),
    CONSTRAINT attribute_defs_name_format_check
        CHECK (name ~ '^[a-z][a-z0-9_]*$'),
    CONSTRAINT attribute_defs_type_check
        CHECK (type IN ('text', 'number', 'boolean', 'select', 'multiselect')),
    CONSTRAINT attribute_defs_sort_order_check
        CHECK (sort_order >= 0)
);

COMMENT ON TABLE attribute_definitions IS
    'Global catalog definitions. Applicability and requiredness are product-specific.';
COMMENT ON COLUMN attribute_definitions.name IS
    'Lowercase snake_case machine key used in product_variants.attributes.';
COMMENT ON COLUMN attribute_definitions.is_active IS
    'Inactive definitions cannot be assigned to products or used by variants.';
COMMENT ON CONSTRAINT attribute_defs_name_format_check ON attribute_definitions IS
    'Lowercase snake_case machine name.';

-- ---------------------------------------------------------------------------
-- 4) ATTRIBUTE OPTIONS
-- ---------------------------------------------------------------------------

CREATE TABLE attribute_options (
    id             BIGSERIAL,
    attribute_id   BIGINT       NOT NULL,
    value          VARCHAR(100) NOT NULL,
    display_name   VARCHAR(200),
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order     INT          NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT attribute_options_pkey PRIMARY KEY (id),
    CONSTRAINT attribute_options_attribute_id_fkey
        FOREIGN KEY (attribute_id)
        REFERENCES attribute_definitions(id)
        ON DELETE RESTRICT,
    CONSTRAINT attribute_options_value_format_check
        CHECK (NULLIF(btrim(value), '') IS NOT NULL
               AND value = btrim(value)
               AND value = lower(value)),
    CONSTRAINT attribute_options_sort_order_check
        CHECK (sort_order >= 0),
    CONSTRAINT attribute_options_unique_value_key
        UNIQUE (attribute_id, value)
);

COMMENT ON TABLE attribute_options IS
    'Allowed lowercase machine values for select and multiselect definitions.';
COMMENT ON COLUMN attribute_options.value IS
    'Lowercase machine value stored inside variant attributes.';
COMMENT ON COLUMN attribute_options.is_active IS
    'Inactive options cannot be used by new or updated variants.';
COMMENT ON CONSTRAINT attribute_options_value_format_check ON attribute_options IS
    'Option values are non-empty, trimmed, lowercase machine values.';

-- ---------------------------------------------------------------------------
-- 5) PRODUCT-SPECIFIC ATTRIBUTE CONFIGURATION
-- ---------------------------------------------------------------------------

CREATE TABLE product_attribute_definitions (
    product_id   BIGINT      NOT NULL,
    attribute_id BIGINT      NOT NULL,
    is_required  BOOLEAN     NOT NULL DEFAULT FALSE,
    sort_order   INT         NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT product_attribute_definitions_pkey
        PRIMARY KEY (product_id, attribute_id),
    CONSTRAINT product_attribute_definitions_product_fkey
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT,
    CONSTRAINT product_attribute_definitions_attribute_fkey
        FOREIGN KEY (attribute_id)
        REFERENCES attribute_definitions(id)
        ON DELETE RESTRICT,
    CONSTRAINT product_attribute_definitions_sort_order_check
        CHECK (sort_order >= 0)
);

COMMENT ON TABLE product_attribute_definitions IS
    'Product-specific attribute applicability, requiredness, and display ordering.';
COMMENT ON COLUMN product_attribute_definitions.is_required IS
    'The sole authoritative requiredness rule for variants of this product.';
COMMENT ON CONSTRAINT product_attribute_definitions_pkey ON product_attribute_definitions IS
    'Prevents duplicate product/attribute configuration.';

-- The primary key covers lookup by product_id. This reverse index supports
-- administration queries that find products using an attribute.
CREATE INDEX idx_product_attribute_definitions_attribute
    ON product_attribute_definitions (attribute_id, product_id);

-- ---------------------------------------------------------------------------
-- 6) PRODUCT VARIANTS
-- ---------------------------------------------------------------------------

CREATE TABLE product_variants (
    id               BIGSERIAL,
    product_id       BIGINT       NOT NULL,
    sku              VARCHAR(100) NOT NULL,
    name             VARCHAR(300),
    price            NUMERIC(12,2) NOT NULL,
    compare_at_price NUMERIC(12,2),
    cost_price       NUMERIC(12,2),
    weight_grams     INT,
    dimensions       JSONB,
    attributes       JSONB        NOT NULL DEFAULT '{}'::JSONB,
    media            JSONB        NOT NULL DEFAULT '[]'::JSONB,
    is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    deleted_at       TIMESTAMPTZ,

    CONSTRAINT product_variants_pkey PRIMARY KEY (id),
    CONSTRAINT product_variants_product_id_fkey
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT,
    CONSTRAINT product_variants_sku_key UNIQUE (sku),
    CONSTRAINT variants_sku_format_check
        CHECK (sku ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$'),
    CONSTRAINT variants_price_nonnegative_check
        CHECK (price >= 0),
    CONSTRAINT variants_compare_at_price_check
        CHECK (compare_at_price IS NULL OR compare_at_price >= price),
    CONSTRAINT variants_cost_price_check
        CHECK (cost_price IS NULL OR cost_price >= 0),
    CONSTRAINT variants_weight_check
        CHECK (weight_grams IS NULL OR weight_grams > 0),
    CONSTRAINT variants_dimensions_structure_check
        CHECK (fn_chk_dimensions(dimensions)),
    CONSTRAINT variants_attributes_structure_check
        CHECK (jsonb_typeof(attributes) = 'object'),
    CONSTRAINT variants_media_structure_check
        CHECK (fn_chk_media_array(media)),
    CONSTRAINT variants_deleted_state_check
        CHECK (deleted_at IS NULL OR is_active = FALSE)
);

COMMENT ON TABLE product_variants IS
    'Purchasable SKUs. Every published product must have a sellable variant.';
COMMENT ON COLUMN product_variants.sku IS
    'Globally unique uppercase kebab-case SKU.';
COMMENT ON COLUMN product_variants.cost_price IS
    'Internal margin input. Exclude from customer-facing API responses.';
COMMENT ON COLUMN product_variants.attributes IS
    'JSONB object validated against product_attribute_definitions and active options.';
COMMENT ON COLUMN product_variants.deleted_at IS
    'Soft-delete timestamp. Hard DELETE/TRUNCATE and restoration are blocked.';
COMMENT ON CONSTRAINT product_variants_product_id_fkey ON product_variants IS
    'ON DELETE RESTRICT protects SKU and order-history references.';
COMMENT ON CONSTRAINT variants_sku_format_check ON product_variants IS
    'Uppercase alphanumeric SKU with internal hyphens only.';

-- ---------------------------------------------------------------------------
-- 7) Indexes
-- ---------------------------------------------------------------------------

CREATE INDEX idx_products_category_id
    ON products (category_id);

CREATE INDEX idx_products_featured
    ON products (id)
    WHERE is_featured = TRUE
      AND is_active = TRUE
      AND status = 'published'
      AND deleted_at IS NULL;

CREATE INDEX idx_products_fts
    ON products USING GIN (search_vector);

CREATE INDEX idx_products_specifications
    ON products USING GIN (specifications);

CREATE INDEX idx_products_tags
    ON products USING GIN (tags);

CREATE INDEX idx_products_category_cover
    ON products (category_id, id)
    INCLUDE (name, slug, is_featured, status)
    WHERE is_active = TRUE
      AND status = 'published'
      AND deleted_at IS NULL;

-- The unique constraint's index covers attribute_id + value existence checks;
-- this separate index covers ordered option listings and active-option scans.
CREATE INDEX idx_attribute_options_by_attribute
    ON attribute_options (attribute_id, is_active, sort_order);

CREATE INDEX idx_variants_product_id
    ON product_variants (product_id);

CREATE INDEX idx_variants_active_by_product
    ON product_variants (product_id)
    WHERE is_active = TRUE
      AND deleted_at IS NULL;

CREATE INDEX idx_variants_attributes
    ON product_variants USING GIN (attributes);

-- ---------------------------------------------------------------------------
-- 8) Deterministic lock helpers
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_lock_attribute_ids(p_attribute_ids BIGINT[])
RETURNS VOID
LANGUAGE plpgsql
VOLATILE
AS $$
DECLARE
    v_attribute_id BIGINT;
BEGIN
    -- A 64-bit hash of a fixed namespace plus the BIGINT ID supports the full
    -- BIGINT range. The same protocol is used by every metadata/variant path.
    -- Transaction-level locks are released automatically at COMMIT/ROLLBACK.
    FOR v_attribute_id IN
        SELECT DISTINCT id
        FROM unnest(COALESCE(p_attribute_ids, ARRAY[]::BIGINT[])) AS u(id)
        WHERE id IS NOT NULL
        ORDER BY id
    LOOP
        PERFORM pg_advisory_xact_lock(
            hashtextextended(
                'commerce.catalog.attribute:' || v_attribute_id::TEXT,
                0
            )
        );
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION fn_lock_product_ids(p_product_ids BIGINT[])
RETURNS VOID
LANGUAGE plpgsql
VOLATILE
AS $$
DECLARE
    v_product_id BIGINT;
    v_locked_id  BIGINT;
BEGIN
    -- Product row locks are acquired in ascending order for every path. This
    -- prevents opposite product moves from deadlocking on parent rows.
    FOR v_product_id IN
        SELECT DISTINCT id
        FROM unnest(COALESCE(p_product_ids, ARRAY[]::BIGINT[])) AS u(id)
        WHERE id IS NOT NULL
        ORDER BY id
    LOOP
        SELECT p.id
        INTO v_locked_id
        FROM products p
        WHERE p.id = v_product_id
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Product id = % does not exist', v_product_id;
        END IF;
    END LOOP;
END;
$$;

COMMENT ON FUNCTION fn_lock_attribute_ids(BIGINT[]) IS
    'Deterministic transaction-level advisory locks for sorted BIGINT attribute IDs.';
COMMENT ON FUNCTION fn_lock_product_ids(BIGINT[]) IS
    'Ascending product-row locks shared by variant and sellability paths.';

-- ---------------------------------------------------------------------------
-- 9) Generic lifecycle functions
-- Note: fn_set_updated_at() is (re)created identically by
-- 018_functions_triggers.sql; CREATE OR REPLACE makes the overlap harmless.
-- Search-vector maintenance is owned exclusively by 018.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_set_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION fn_enforce_soft_delete_state()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE'
       AND OLD.deleted_at IS NOT NULL
       AND NEW.deleted_at IS NULL THEN
        RAISE EXCEPTION
            'Soft-deleted % id = % cannot be restored', TG_TABLE_NAME, OLD.id
            USING ERRCODE = 'P0011';
    END IF;

    IF NEW.deleted_at IS NOT NULL THEN
        NEW.is_active := FALSE;
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION fn_block_hard_delete()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION
        'Hard DELETE is disabled for %. Use the soft-delete fields instead.',
        TG_TABLE_NAME
        USING ERRCODE = 'P0009';
END;
$$;

CREATE OR REPLACE FUNCTION fn_block_truncate()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION
        'TRUNCATE is disabled for %. Use the approved soft-delete workflow instead.',
        TG_TABLE_NAME
        USING ERRCODE = 'P0009';
END;
$$;

-- ---------------------------------------------------------------------------
-- 10) Product-scoped attribute configuration guards
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_validate_product_attribute_definition()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_old_name TEXT;
    v_new_name TEXT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM fn_lock_product_ids(ARRAY[OLD.product_id]);
        PERFORM fn_lock_attribute_ids(ARRAY[OLD.attribute_id]);

        SELECT name
        INTO v_old_name
        FROM attribute_definitions
        WHERE id = OLD.attribute_id;

        IF v_old_name IS NULL THEN
            RAISE EXCEPTION
                'Attribute definition % does not exist', OLD.attribute_id
                USING ERRCODE = 'P0010';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM product_variants v
            WHERE v.product_id = OLD.product_id
              AND v.attributes ? v_old_name
        ) THEN
            RAISE EXCEPTION
                'Cannot remove attribute % from product %: existing variants use it',
                v_old_name, OLD.product_id
                USING ERRCODE = 'P0010';
        END IF;

        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        PERFORM fn_lock_product_ids(ARRAY[OLD.product_id, NEW.product_id]);
        PERFORM fn_lock_attribute_ids(ARRAY[OLD.attribute_id, NEW.attribute_id]);
    ELSE
        PERFORM fn_lock_product_ids(ARRAY[NEW.product_id]);
        PERFORM fn_lock_attribute_ids(ARRAY[NEW.attribute_id]);
    END IF;

    SELECT name
    INTO v_new_name
    FROM attribute_definitions
    WHERE id = NEW.attribute_id
      AND is_active = TRUE;

    IF v_new_name IS NULL THEN
        RAISE EXCEPTION
            'Cannot assign inactive or missing attribute definition % to product %',
            NEW.attribute_id, NEW.product_id
            USING ERRCODE = 'P0010';
    END IF;

    IF TG_OP = 'UPDATE'
       AND (OLD.product_id IS DISTINCT FROM NEW.product_id
            OR OLD.attribute_id IS DISTINCT FROM NEW.attribute_id) THEN
        SELECT name
        INTO v_old_name
        FROM attribute_definitions
        WHERE id = OLD.attribute_id;

        IF EXISTS (
            SELECT 1
            FROM product_variants v
            WHERE v.product_id = OLD.product_id
              AND v.attributes ? v_old_name
        ) THEN
            RAISE EXCEPTION
                'Cannot remove attribute % from product %: existing variants use it',
                v_old_name, OLD.product_id
                USING ERRCODE = 'P0010';
        END IF;
    END IF;

    -- Relaxing requiredness is allowed. Tightening it is allowed only when all
    -- existing variants for this product already contain the attribute.
    IF NEW.is_required
       AND (
           TG_OP = 'INSERT'
           OR OLD.is_required = FALSE
           OR OLD.product_id IS DISTINCT FROM NEW.product_id
           OR OLD.attribute_id IS DISTINCT FROM NEW.attribute_id
       )
       AND EXISTS (
           SELECT 1
           FROM product_variants v
           WHERE v.product_id = NEW.product_id
             AND NOT (v.attributes ? v_new_name)
       ) THEN
        RAISE EXCEPTION
            'Cannot require attribute % for product %: existing variants are missing it',
            v_new_name, NEW.product_id
            USING ERRCODE = 'P0010';
    END IF;

    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------------
-- 11) Global attribute and option guards
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_guard_attribute_definition_update()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM fn_lock_attribute_ids(ARRAY[OLD.id]);

    IF OLD.name IS DISTINCT FROM NEW.name
       AND EXISTS (
           SELECT 1
           FROM product_variants v
           WHERE v.attributes ? OLD.name
       ) THEN
        RAISE EXCEPTION
            'Cannot rename in-use attribute %', OLD.name
            USING ERRCODE = 'P0010';
    END IF;

    IF OLD.type IS DISTINCT FROM NEW.type
       AND EXISTS (
           SELECT 1
           FROM product_variants v
           WHERE v.attributes ? OLD.name
       ) THEN
        RAISE EXCEPTION
            'Cannot change type of in-use attribute %', OLD.name
            USING ERRCODE = 'P0010';
    END IF;

    IF OLD.type IS DISTINCT FROM NEW.type
       AND EXISTS (
           SELECT 1
           FROM attribute_options ao
           WHERE ao.attribute_id = OLD.id
       ) THEN
        RAISE EXCEPTION
            'Cannot change attribute % type while options exist', OLD.name
            USING ERRCODE = 'P0010';
    END IF;

    IF OLD.is_active = TRUE
       AND NEW.is_active = FALSE
       AND EXISTS (
           SELECT 1
           FROM product_attribute_definitions pad
           WHERE pad.attribute_id = OLD.id
       ) THEN
        RAISE EXCEPTION
            'Cannot deactivate attribute % while it is configured for products',
            OLD.name
            USING ERRCODE = 'P0010';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION fn_validate_attribute_option()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_type      TEXT;
    v_is_active BOOLEAN;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        PERFORM fn_lock_attribute_ids(ARRAY[OLD.attribute_id, NEW.attribute_id]);
    ELSE
        PERFORM fn_lock_attribute_ids(ARRAY[NEW.attribute_id]);
    END IF;

    SELECT type, is_active
    INTO v_type, v_is_active
    FROM attribute_definitions
    WHERE id = NEW.attribute_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Attribute definition % does not exist', NEW.attribute_id
            USING ERRCODE = 'P0010';
    END IF;

    IF NOT v_is_active THEN
        RAISE EXCEPTION
            'Cannot add or modify an option under inactive attribute %',
            NEW.attribute_id
            USING ERRCODE = 'P0010';
    END IF;

    IF v_type NOT IN ('select', 'multiselect') THEN
        RAISE EXCEPTION
            'Attribute % of type % cannot have attribute_options',
            NEW.attribute_id, v_type
            USING ERRCODE = 'P0010';
    END IF;

    IF NULLIF(btrim(NEW.value), '') IS NULL
       OR NEW.value <> btrim(NEW.value)
       OR NEW.value <> lower(NEW.value) THEN
        RAISE EXCEPTION
            'Attribute option values must be non-empty, trimmed lowercase values'
            USING ERRCODE = 'P0010';
    END IF;

    IF NEW.display_name IS NULL THEN
        NEW.display_name := NEW.value;
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION fn_guard_attribute_option_update()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_attribute_name TEXT;
    v_attribute_type TEXT;
    v_is_used        BOOLEAN;
BEGIN
    PERFORM fn_lock_attribute_ids(ARRAY[OLD.attribute_id, NEW.attribute_id]);

    SELECT name, type
    INTO v_attribute_name, v_attribute_type
    FROM attribute_definitions
    WHERE id = OLD.attribute_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Parent attribute definition % no longer exists', OLD.attribute_id
            USING ERRCODE = 'P0010';
    END IF;

    SELECT EXISTS (
        SELECT 1
        FROM product_variants v
        WHERE v.attributes ? v_attribute_name
          AND (
              (v_attribute_type = 'select'
               AND v.attributes ->> v_attribute_name = OLD.value)
              OR
              (v_attribute_type = 'multiselect'
               AND jsonb_typeof(v.attributes -> v_attribute_name) = 'array'
               AND v.attributes -> v_attribute_name
                   @> to_jsonb(ARRAY[OLD.value]::TEXT[]))
          )
    )
    INTO v_is_used;

    IF v_is_used
       AND (
           OLD.attribute_id IS DISTINCT FROM NEW.attribute_id
           OR OLD.value IS DISTINCT FROM NEW.value
           OR (OLD.is_active = TRUE AND NEW.is_active = FALSE)
       ) THEN
        RAISE EXCEPTION
            'Cannot change or deactivate option % for in-use attribute %',
            OLD.value, v_attribute_name
            USING ERRCODE = 'P0010';
    END IF;

    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------------
-- 12) Product-scoped variant JSONB validation
-- Parent-row locking is handled by trg_product_variants_00_parent_lock, which
-- always fires before this function (same-event triggers run in name order).
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_validate_variant_attributes()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_key             TEXT;
    v_value           JSONB;
    v_type            TEXT;
    v_attribute_id    BIGINT;
    v_is_required     BOOLEAN;
    v_option_exists   BOOLEAN;
    v_total           BIGINT;
    v_distinct        BIGINT;
    v_required_key    TEXT;
    v_attribute_ids   BIGINT[];
    v_config_ids      BIGINT[];
BEGIN
    IF jsonb_typeof(NEW.attributes) <> 'object' THEN
        RAISE EXCEPTION
            'Variant attributes must be a JSONB object'
            USING ERRCODE = 'P0006';
    END IF;

    -- Single-scan unknown-key detection (optimized: was two COUNT scans).
    IF EXISTS (
        SELECT 1
        FROM jsonb_object_keys(NEW.attributes) AS k(key)
        LEFT JOIN attribute_definitions ad ON ad.name = k.key
        WHERE ad.id IS NULL
    ) THEN
        RAISE EXCEPTION
            'Variant contains an unknown attribute key for product %', NEW.product_id
            USING ERRCODE = 'P0006';
    END IF;

    SELECT COALESCE(array_agg(ad.id ORDER BY ad.id), ARRAY[]::BIGINT[])
    INTO v_attribute_ids
    FROM jsonb_object_keys(NEW.attributes) AS k(key)
    JOIN attribute_definitions ad ON ad.name = k.key;

    SELECT COALESCE(array_agg(pad.attribute_id ORDER BY pad.attribute_id), ARRAY[]::BIGINT[])
    INTO v_config_ids
    FROM product_attribute_definitions pad
    WHERE pad.product_id = NEW.product_id;

    PERFORM fn_lock_attribute_ids(
        ARRAY(
            SELECT id
            FROM (
                SELECT unnest(v_attribute_ids) AS id
                UNION
                SELECT unnest(v_config_ids) AS id
            ) AS ids
        )
    );

    FOR v_key, v_value IN
        SELECT key, value FROM jsonb_each(NEW.attributes)
    LOOP
        SELECT ad.id, ad.type, pad.is_required
        INTO v_attribute_id, v_type, v_is_required
        FROM product_attribute_definitions pad
        JOIN attribute_definitions ad ON ad.id = pad.attribute_id
        WHERE pad.product_id = NEW.product_id
          AND ad.name = v_key
          AND ad.is_active = TRUE;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Attribute "%" is not configured and active for product %',
                v_key, NEW.product_id
                USING ERRCODE = 'P0006';
        END IF;

        IF v_type = 'number' THEN
            IF jsonb_typeof(v_value) <> 'number' THEN
                RAISE EXCEPTION
                    'Attribute "%" requires a JSON number', v_key
                    USING ERRCODE = 'P0006';
            END IF;

        ELSIF v_type = 'boolean' THEN
            IF jsonb_typeof(v_value) <> 'boolean' THEN
                RAISE EXCEPTION
                    'Attribute "%" requires a JSON boolean', v_key
                    USING ERRCODE = 'P0006';
            END IF;

        ELSIF v_type = 'text' THEN
            IF jsonb_typeof(v_value) <> 'string' THEN
                RAISE EXCEPTION
                    'Attribute "%" requires a JSON string', v_key
                    USING ERRCODE = 'P0006';
            END IF;

            IF v_is_required
               AND NULLIF(btrim(v_value #>> '{}'), '') IS NULL THEN
                RAISE EXCEPTION
                    'Required text attribute "%" cannot be empty', v_key
                    USING ERRCODE = 'P0006';
            END IF;

        ELSIF v_type = 'select' THEN
            IF jsonb_typeof(v_value) <> 'string' THEN
                RAISE EXCEPTION
                    'Attribute "%" requires one string option value', v_key
                    USING ERRCODE = 'P0006';
            END IF;

            SELECT EXISTS (
                SELECT 1
                FROM attribute_options ao
                WHERE ao.attribute_id = v_attribute_id
                  AND ao.value = v_value #>> '{}'
                  AND ao.is_active = TRUE
            )
            INTO v_option_exists;

            IF NOT v_option_exists THEN
                RAISE EXCEPTION
                    'Attribute "%" contains an unregistered or inactive option',
                    v_key
                    USING ERRCODE = 'P0006';
            END IF;

        ELSIF v_type = 'multiselect' THEN
            IF jsonb_typeof(v_value) <> 'array' THEN
                RAISE EXCEPTION
                    'Attribute "%" requires an array of string option values', v_key
                    USING ERRCODE = 'P0006';
            END IF;

            IF v_is_required AND v_value = '[]'::JSONB THEN
                RAISE EXCEPTION
                    'Required multiselect attribute "%" cannot be empty', v_key
                    USING ERRCODE = 'P0006';
            END IF;

            IF EXISTS (
                SELECT 1
                FROM jsonb_array_elements(v_value) AS item(value)
                WHERE jsonb_typeof(item.value) <> 'string'
            ) THEN
                RAISE EXCEPTION
                    'Attribute "%" requires an array containing only strings', v_key
                    USING ERRCODE = 'P0006';
            END IF;

            SELECT count(*), count(DISTINCT option_value)
            INTO v_total, v_distinct
            FROM jsonb_array_elements_text(v_value) AS arr(option_value);

            IF v_total <> v_distinct THEN
                RAISE EXCEPTION
                    'Attribute "%" cannot contain duplicate options', v_key
                    USING ERRCODE = 'P0006';
            END IF;

            IF EXISTS (
                SELECT 1
                FROM jsonb_array_elements_text(v_value) AS arr(option_value)
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM attribute_options ao
                    WHERE ao.attribute_id = v_attribute_id
                      AND ao.value = arr.option_value
                      AND ao.is_active = TRUE
                )
            ) THEN
                RAISE EXCEPTION
                    'Attribute "%" contains an unregistered or inactive option',
                    v_key
                    USING ERRCODE = 'P0006';
            END IF;
        END IF;
    END LOOP;

    FOR v_required_key IN
        SELECT ad.name
        FROM product_attribute_definitions pad
        JOIN attribute_definitions ad ON ad.id = pad.attribute_id
        WHERE pad.product_id = NEW.product_id
          AND pad.is_required = TRUE
          AND ad.is_active = TRUE
    LOOP
        IF NOT (NEW.attributes ? v_required_key) THEN
            RAISE EXCEPTION
                'Variant is missing required product attribute "%"',
                v_required_key
                USING ERRCODE = 'P0006';
        END IF;
    END LOOP;

    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------------
-- 13) Product status and sellability
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_validate_product_status_transition()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status = 'archived' THEN
            RAISE EXCEPTION
                'A product cannot be inserted directly as archived; insert as draft or published'
                USING ERRCODE = 'P0007';
        END IF;
        RETURN NEW;
    END IF;

    PERFORM fn_lock_product_ids(ARRAY[NEW.id]);

    IF OLD.status = NEW.status THEN
        RETURN NEW;
    END IF;

    IF OLD.deleted_at IS NOT NULL OR NEW.deleted_at IS NOT NULL THEN
        RAISE EXCEPTION
            'Cannot change status of a soft-deleted product id = %', OLD.id
            USING ERRCODE = 'P0007';
    END IF;

    IF OLD.status = 'draft'
       AND NEW.status IN ('published', 'archived') THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'published'
       AND NEW.status = 'archived' THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION
        'Invalid product status transition: % -> % for product id = %',
        OLD.status, NEW.status, OLD.id
        USING ERRCODE = 'P0007';
END;
$$;

CREATE OR REPLACE FUNCTION fn_assert_product_sellable_variant(p_product_id BIGINT)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_locked_id BIGINT;
BEGIN
    SELECT p.id
    INTO v_locked_id
    FROM products p
    WHERE p.id = p_product_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM products p
        WHERE p.id = p_product_id
          AND p.status = 'published'
          AND p.is_active = TRUE
          AND p.deleted_at IS NULL
    )
    AND NOT EXISTS (
        SELECT 1
        FROM product_variants v
        WHERE v.product_id = p_product_id
          AND v.is_active = TRUE
          AND v.deleted_at IS NULL
          AND v.price > 0
    ) THEN
        RAISE EXCEPTION
            'Published product id = % requires at least one sellable variant '
            '(active, non-deleted, price > 0)', p_product_id
            USING ERRCODE = 'P0008';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION fn_deferred_product_sellability_check()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_TABLE_NAME = 'products' THEN
        PERFORM fn_assert_product_sellable_variant(NEW.id);
    ELSIF TG_OP = 'DELETE' THEN
        PERFORM fn_assert_product_sellable_variant(OLD.product_id);
    ELSE
        PERFORM fn_assert_product_sellable_variant(NEW.product_id);

        IF TG_OP = 'UPDATE'
           AND OLD.product_id IS DISTINCT FROM NEW.product_id THEN
            PERFORM fn_assert_product_sellable_variant(OLD.product_id);
        END IF;
    END IF;

    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION fn_lock_variant_products()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        PERFORM fn_lock_product_ids(ARRAY[NEW.product_id]);
        RETURN NEW;
    ELSIF TG_OP = 'DELETE' THEN
        PERFORM fn_lock_product_ids(ARRAY[OLD.product_id]);
        RETURN OLD;
    ELSE
        PERFORM fn_lock_product_ids(ARRAY[OLD.product_id, NEW.product_id]);
        RETURN NEW;
    END IF;
END;
$$;

COMMENT ON FUNCTION fn_validate_variant_attributes() IS
    'Product-scoped JSONB validation using product_attribute_definitions; raises P0006.';
COMMENT ON FUNCTION fn_validate_product_status_transition() IS
    'Enforces draft -> published/archived, published -> archived, archived terminal; raises P0007.';
COMMENT ON FUNCTION fn_assert_product_sellable_variant(BIGINT) IS
    'Locks the product row and enforces active published product sellability; raises P0008.';

-- ---------------------------------------------------------------------------
-- 14) Triggers
-- Note: updated_at triggers for products/product_variants and the products
-- search-vector trigger live in 018_functions_triggers.sql (single owner,
-- no duplicate firing).
-- ---------------------------------------------------------------------------

-- Products
CREATE TRIGGER trg_products_01_soft_delete_state
BEFORE INSERT OR UPDATE ON products
FOR EACH ROW
EXECUTE FUNCTION fn_enforce_soft_delete_state();

CREATE TRIGGER trg_products_02_status_transition
BEFORE INSERT OR UPDATE OF status ON products
FOR EACH ROW
EXECUTE FUNCTION fn_validate_product_status_transition();

CREATE TRIGGER trg_products_90_no_hard_delete
BEFORE DELETE ON products
FOR EACH ROW
EXECUTE FUNCTION fn_block_hard_delete();

CREATE TRIGGER trg_products_91_no_truncate
BEFORE TRUNCATE ON products
FOR EACH STATEMENT
EXECUTE FUNCTION fn_block_truncate();

-- Global attribute definitions
CREATE TRIGGER trg_attribute_definitions_00_guard
BEFORE UPDATE ON attribute_definitions
FOR EACH ROW
EXECUTE FUNCTION fn_guard_attribute_definition_update();

CREATE TRIGGER trg_attribute_definitions_01_updated_at
BEFORE UPDATE ON attribute_definitions
FOR EACH ROW
EXECUTE FUNCTION fn_set_updated_at();

CREATE TRIGGER trg_attribute_definitions_90_no_hard_delete
BEFORE DELETE ON attribute_definitions
FOR EACH ROW
EXECUTE FUNCTION fn_block_hard_delete();

CREATE TRIGGER trg_attribute_definitions_91_no_truncate
BEFORE TRUNCATE ON attribute_definitions
FOR EACH STATEMENT
EXECUTE FUNCTION fn_block_truncate();

-- Attribute options
CREATE TRIGGER trg_attribute_options_00_guard
BEFORE UPDATE ON attribute_options
FOR EACH ROW
EXECUTE FUNCTION fn_guard_attribute_option_update();

CREATE TRIGGER trg_attribute_options_01_validate
BEFORE INSERT OR UPDATE ON attribute_options
FOR EACH ROW
EXECUTE FUNCTION fn_validate_attribute_option();

CREATE TRIGGER trg_attribute_options_02_updated_at
BEFORE UPDATE ON attribute_options
FOR EACH ROW
EXECUTE FUNCTION fn_set_updated_at();

CREATE TRIGGER trg_attribute_options_90_no_hard_delete
BEFORE DELETE ON attribute_options
FOR EACH ROW
EXECUTE FUNCTION fn_block_hard_delete();

CREATE TRIGGER trg_attribute_options_91_no_truncate
BEFORE TRUNCATE ON attribute_options
FOR EACH STATEMENT
EXECUTE FUNCTION fn_block_truncate();

-- Product-specific configuration
CREATE TRIGGER trg_product_attributes_00_guard
BEFORE INSERT OR UPDATE OR DELETE ON product_attribute_definitions
FOR EACH ROW
EXECUTE FUNCTION fn_validate_product_attribute_definition();

CREATE TRIGGER trg_product_attributes_01_updated_at
BEFORE UPDATE ON product_attribute_definitions
FOR EACH ROW
EXECUTE FUNCTION fn_set_updated_at();

CREATE TRIGGER trg_product_attributes_91_no_truncate
BEFORE TRUNCATE ON product_attribute_definitions
FOR EACH STATEMENT
EXECUTE FUNCTION fn_block_truncate();

-- Variants: parent product lock must run before JSONB validation
-- (guaranteed: same-event triggers fire in name order, 00 < 01 < 02).
CREATE TRIGGER trg_product_variants_00_parent_lock
BEFORE INSERT OR UPDATE OR DELETE ON product_variants
FOR EACH ROW
EXECUTE FUNCTION fn_lock_variant_products();

CREATE TRIGGER trg_product_variants_01_soft_delete_state
BEFORE INSERT OR UPDATE ON product_variants
FOR EACH ROW
EXECUTE FUNCTION fn_enforce_soft_delete_state();

CREATE TRIGGER trg_product_variants_02_attribute_validation
BEFORE INSERT OR UPDATE OF product_id, attributes ON product_variants
FOR EACH ROW
EXECUTE FUNCTION fn_validate_variant_attributes();

CREATE TRIGGER trg_product_variants_90_no_hard_delete
BEFORE DELETE ON product_variants
FOR EACH ROW
EXECUTE FUNCTION fn_block_hard_delete();

CREATE TRIGGER trg_product_variants_91_no_truncate
BEFORE TRUNCATE ON product_variants
FOR EACH STATEMENT
EXECUTE FUNCTION fn_block_truncate();

-- Deferred checks allow product and first variant to be created in one
-- transaction, while still checking the final state at COMMIT.
CREATE CONSTRAINT TRIGGER trg_products_sellability_deferred
AFTER INSERT OR UPDATE OF status, is_active, deleted_at ON products
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION fn_deferred_product_sellability_check();

CREATE CONSTRAINT TRIGGER trg_variants_sellability_deferred
AFTER INSERT OR UPDATE OF product_id, is_active, deleted_at, price OR DELETE
ON product_variants
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION fn_deferred_product_sellability_check();

COMMIT;

-- ============================================================================
-- SEED TEMPLATE (run manually after migration — admin data, not DDL):
--
--   INSERT INTO attribute_options (attribute_id, value, display_name, sort_order)
--   SELECT d.id, o.value, o.display, o.ord
--   FROM (VALUES
--       ('color', 'red',    'Red',    1),
--       ('color', 'blue',   'Blue',   2),
--       ('color', 'black',  'Black',  3),
--       ('size',  's',      'Small',  1),
--       ('size',  'm',      'Medium', 2),
--       ('size',  'l',      'Large',  3),
--       ('size',  'xl',     'X-Large',4)
--   ) AS o(attr, value, display, ord)
--   JOIN attribute_definitions d ON d.name = o.attr
--   WHERE d.type IN ('select', 'multiselect')
--   ON CONFLICT (attribute_id, value) DO NOTHING;
--
-- Application/API requirements not enforceable by this migration alone:
--   * Exclude cost_price from customer-facing responses and views.
--   * Enforce authorization for catalog updates and soft deletion.
--   * Use the same text-search configuration ('simple') in search queries
--     (must match trg_products_search_vector in 018).
--   * Ensure every application write path follows the lock protocol.
--   * Apply this migration once; do not rerun it after a successful deployment.
-- ============================================================================