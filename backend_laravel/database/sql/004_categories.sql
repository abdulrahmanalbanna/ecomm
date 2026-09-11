-- =============================================================================
-- Migration 004 : Product Categories
-- Project       : commerce-single-vendor
-- Tables        : categories
-- Depends on    : 001_extensions_enums.sql (ltree extension)
-- Design note   : Self-referential hierarchy using the ltree extension.
--                 Confirmed decision: each product belongs to exactly ONE category.
--                 ON DELETE RESTRICT on parent_id prevents orphaned subtrees.
-- =============================================================================

BEGIN;

CREATE TABLE categories (
    id          BIGSERIAL    PRIMARY KEY,
    -- Self-referential. NULL = root category (top-level).
    -- ON DELETE RESTRICT: cannot delete a parent that has children.
    parent_id   BIGINT       REFERENCES categories(id) ON DELETE RESTRICT
                             CHECK (parent_id IS NULL OR parent_id <> id),
    -- URL-safe slug used in breadcrumbs and category URLs.
    slug        VARCHAR(200) UNIQUE NOT NULL,
    name        VARCHAR(200) NOT NULL,
    description TEXT,
    image_url   TEXT,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    -- Controls display ordering within a parent (lower = first).
    sort_order  INT          NOT NULL DEFAULT 0,
    -- ltree path: dot-separated label path mirroring the category tree.
    -- Example: 'Electronics.Phones.Smartphones'
    -- Enables: SELECT * FROM categories WHERE path <@ 'Electronics'  (subtree)
    --          SELECT * FROM categories WHERE path @> 'Electronics.Phones.Smartphones'  (ancestors)
    -- MUST be kept in sync with parent_id hierarchy via application logic or trigger.
    path        LTREE        UNIQUE NOT NULL,
    -- Cached depth level. Root = 0. Maintained by application on insert/update.
    depth       SMALLINT     NOT NULL DEFAULT 0 CHECK (depth >= 0 AND depth <= 32),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  categories           IS 'Hierarchical product category tree. Uses ltree and supporting indexes to accelerate subtree and ancestor queries.';
COMMENT ON COLUMN categories.parent_id IS 'NULL = root category. ON DELETE RESTRICT prevents deleting a parent with children. CHECK (parent_id <> id) prevents self-referential cycles at the DB level.';
COMMENT ON COLUMN categories.slug      IS 'URL-safe unique identifier. Used in SEO category URLs. Must be globally unique.';
COMMENT ON COLUMN categories.path      IS 'ltree label path mirroring the category hierarchy.';
COMMENT ON COLUMN categories.depth     IS 'Cached tree depth (0-based). Root = 0. Avoids counting hops at query time. Upper bound CHECK (depth <= 32) prevents runaway deep trees.';

-- B-Tree index: child lookup by parent (common admin tree-rendering query)
CREATE INDEX idx_categories_parent_id
    ON categories (parent_id);

-- GiST index on ltree path: enables subtree (<@) and ancestor (@>) queries in O(log n).
CREATE INDEX idx_categories_path_gist
    ON categories USING GIST (path);

-- B-Tree index on ltree path: enables exact match and lexicographic ordering.
-- CREATE INDEX idx_categories_path_btree
--     ON categories USING BTREE (path);

-- Partial index: only active categories are shown to customers.
CREATE INDEX idx_categories_active_sorted
    ON categories (parent_id, sort_order, id)
    WHERE is_active = TRUE;

-- ---------------------------------------------------------------------------
-- PATH/DEPTH MAINTENANCE TRIGGERS
-- path and depth are derived columns; without triggers they drift silently
-- from parent_id. Label scheme: slug → ltree label ([A-Za-z0-9_] only;
-- hyphens replaced with underscores). The CHECK constraint below disallows
-- underscores in slugs, so 'kids-toys' and 'kids_toys' label collisions are
-- impossible within this schema's own data.
-- ---------------------------------------------------------------------------

-- Slug format CHECK: lowercase alphanumeric + internal hyphens only.
-- Guarantees the hyphen→underscore label derivation inside the trigger can
-- never hit an invalid ltree character or a collision.
ALTER TABLE categories
    ADD CONSTRAINT chk_categories_slug_format
        CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$');

COMMENT ON CONSTRAINT chk_categories_slug_format ON categories IS
    'Enforces slug format: lowercase alphanumeric with internal hyphens only '
    '(e.g. ''smart-phones'', ''electronics''). Prevents: uppercase, underscores, '
    'dots, spaces. Rationale: ltree path derivation replaces hyphens with '
    'underscores — disallowing underscores in slugs prevents label collisions '
    'between e.g. ''kids-toys'' and ''kids_toys''.';

CREATE OR REPLACE FUNCTION fn_maintain_category_path()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_parent_path  LTREE;
    v_parent_depth SMALLINT;
    v_label        TEXT;
BEGIN
    -- Serialize hierarchy changes and lock the selected parent before deriving path.
    IF TG_OP = 'UPDATE' THEN
        PERFORM pg_advisory_xact_lock(4004, 1);
    END IF;

    -- Derive a valid ltree label from slug.
    -- The CHECK constraint guarantees no other special characters reach this point.
    v_label := replace(NEW.slug, '-', '_');

    IF NEW.parent_id IS NULL THEN
        -- Root node: path is just the slug label, depth = 0.
        NEW.path  := v_label::LTREE;
        NEW.depth := 0;
    ELSE
        -- Fetch parent path and depth (single lookup for both cycle check and path computation).
        SELECT path, depth
        INTO   v_parent_path, v_parent_depth
        FROM categories
        WHERE id = NEW.parent_id
        FOR UPDATE;

        IF v_parent_path IS NULL THEN
            RAISE EXCEPTION 'Parent category id = % not found.', NEW.parent_id
                USING ERRCODE = 'P0011';
        END IF;

        -- Cycle check: reject if the proposed parent's path is a descendant
        -- of this row's current path (would create an ancestor loop).
        -- On INSERT, OLD.path is NULL so this guard is skipped safely.
        IF TG_OP = 'UPDATE' AND OLD.path IS NOT NULL THEN
            IF v_parent_path <@ OLD.path THEN
                RAISE EXCEPTION
                    'Cycle detected: cannot set parent_id = % because that '
                    'category is already a descendant of category id = %.',
                    NEW.parent_id, NEW.id
                    USING ERRCODE = 'P0010';
            END IF;
        END IF;

        NEW.path  := v_parent_path || v_label::LTREE;
        NEW.depth := v_parent_depth + 1;
    END IF;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_maintain_category_path() IS
    'BEFORE INSERT OR UPDATE OF parent_id, slug trigger. Computes path (ltree) and '
    'depth for the current row from its parent. Labels derived from slug '
    '(hyphens → underscores; CHECK on slug prevents other special chars). '
    'Cycle detection: raises P0010 if re-parenting would create an ancestor loop. '
    'Descendant cascade handled by fn_cascade_category_path() (AFTER trigger).';

-- Fires on INSERT or any UPDATE that touches parent_id OR slug.
-- slug is included because path is derived from it; a slug rename without
-- touching parent_id must still update path for this row and its descendants.
CREATE TRIGGER trg_categories_path_before
    BEFORE INSERT OR UPDATE OF parent_id, slug ON categories
    FOR EACH ROW EXECUTE FUNCTION fn_maintain_category_path();

-- AFTER trigger: cascades path/depth to all descendants when this row's path
-- changes (re-parent OR slug rename). Fires after the BEFORE trigger has
-- written the new path, so <@ comparisons against the stored path are correct.
CREATE OR REPLACE FUNCTION fn_cascade_category_path()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_depth_delta INT;
BEGIN
    -- No-op guard: skip cascade only when BOTH the tree position AND the
    -- row's own path are unchanged. Checking parent_id alone would miss
    -- slug-only renames, which change path but not parent_id.
    IF OLD.parent_id IS NOT DISTINCT FROM NEW.parent_id
       AND OLD.path = NEW.path THEN
        RETURN NEW;
    END IF;

    -- Compute how many levels the depth shifted.
    v_depth_delta := NEW.depth - OLD.depth;

    -- Update every descendant whose path started with OLD.path.
    -- subpath(path, nlevel(OLD.path)) strips the OLD.path prefix;
    -- NEW.path || remainder reconstructs the correct new path.
    -- Exclude this row itself (already updated by the BEFORE trigger).
    UPDATE categories
    SET
        path  = NEW.path || subpath(path, nlevel(OLD.path)),
        depth = depth + v_depth_delta
    WHERE path <@ OLD.path
      AND id <> NEW.id;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_cascade_category_path() IS
    'AFTER UPDATE OF parent_id, slug trigger. When a category is re-parented OR '
    'its slug is renamed, rewrites path and adjusts depth for every descendant '
    'using subpath() prefix replacement. '
    'No-op guard checks OLD.path = NEW.path (not just parent_id) so slug-only '
    'renames correctly cascade. '
    'BEFORE trigger (fn_maintain_category_path) must fire first to establish NEW.path.';

CREATE TRIGGER trg_categories_path_after
    AFTER UPDATE OF parent_id, slug ON categories
    FOR EACH ROW EXECUTE FUNCTION fn_cascade_category_path();

COMMENT ON COLUMN categories.path  IS 'ltree label path mirroring the category hierarchy. Derived from parent chain + slug by trg_categories_path_before.';
COMMENT ON COLUMN categories.depth IS 'Cached tree depth (0-based). Root = 0. Maintained by trg_categories_path_before / trg_categories_path_after.';

-- ---------------------------------------------------------------------------
-- DIRECT PATH / DEPTH MUTATION GUARD (issue 5.1)
-- A raw SQL UPDATE of path or depth bypasses the BEFORE INSERT/UPDATE trigger
-- that keeps those columns in sync with parent_id. Block it explicitly.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_guard_category_derived_columns()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    -- path and depth are managed exclusively by fn_maintain_category_path().
    -- Direct mutation via raw SQL is rejected to prevent silent divergence.
    IF NEW.path IS DISTINCT FROM OLD.path THEN
        RAISE EXCEPTION
            'Direct mutation of categories.path is not allowed. '
            'Change parent_id or slug to trigger the path-maintenance trigger.'
            USING ERRCODE = 'P0010';
    END IF;

    IF NEW.depth IS DISTINCT FROM OLD.depth THEN
        RAISE EXCEPTION
            'Direct mutation of categories.depth is not allowed. '
            'Change parent_id or slug to trigger the path-maintenance trigger.'
            USING ERRCODE = 'P0010';
    END IF;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_guard_category_derived_columns() IS
    'BEFORE UPDATE OF path, depth trigger. Blocks raw SQL mutations of derived columns '
    'that are maintained by fn_maintain_category_path(). Changing parent_id or slug is '
    'the only allowed path to modify path or depth.';

-- Fires only when path or depth is targeted directly by name in an UPDATE statement.
CREATE TRIGGER trg_categories_guard_derived_cols
    BEFORE UPDATE OF path, depth ON categories
    FOR EACH ROW EXECUTE FUNCTION fn_guard_category_derived_columns();

COMMIT;
