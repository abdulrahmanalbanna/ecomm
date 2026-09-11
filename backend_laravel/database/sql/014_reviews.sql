-- =============================================================================
-- Migration 014 : Product Reviews
-- Project       : commerce-single-vendor
-- Tables        : product_reviews
-- Depends on    : 005_products_attributes_variants.sql (products)
--                 002_identity_auth.sql (users)
--                 008_orders.sql (order_items)
-- Design notes  :
--   - Reviews default to is_approved = FALSE (admin moderation required).
--   - order_item_id links review to a verified purchase (prevents fake reviews).
--   - UNIQUE(user_id, product_id): one review per product per customer.
--   - search_vector supports full-text search across review title and body.
--     Populated by trigger defined in 018_functions_triggers.sql.
-- =============================================================================

BEGIN;

CREATE TABLE product_reviews (
    id            BIGSERIAL   PRIMARY KEY,
    product_id    BIGINT      NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    user_id       BIGINT      NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    -- Required link to the specific order line item used to verify the purchase.
    order_item_id BIGINT      NOT NULL UNIQUE REFERENCES order_items(id) ON DELETE RESTRICT,
    rating        SMALLINT    NOT NULL CHECK (rating BETWEEN 1 AND 5),
    title         VARCHAR(200),
    body          TEXT,
    -- Reviews start unapproved. Admin must approve before they are visible to customers.
    is_approved   BOOLEAN     NOT NULL DEFAULT FALSE,
    -- Incremented when other customers mark the review as helpful.
    helpful_votes INT         NOT NULL DEFAULT 0 CHECK (helpful_votes >= 0),
    -- TSVECTOR for full-text search across review content. NOT NULL (empty tsvector
    -- default) mirrors products.search_vector to avoid NULL GIN index ambiguity.
    -- Updated by trigger in 018_functions_triggers.sql.
    search_vector TSVECTOR    NOT NULL DEFAULT ''::TSVECTOR,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- One review per product per customer.
    CONSTRAINT uq_one_review_per_product_per_user UNIQUE (user_id, product_id)
);

COMMENT ON TABLE  product_reviews              IS 'Product reviews. Admin-moderated (is_approved = FALSE by default). Every review requires a verified order item owned by the reviewer for the reviewed product.';
COMMENT ON COLUMN product_reviews.is_approved  IS 'FALSE by default. Admin must approve before display. Partial index on is_approved = TRUE for display queries.';
COMMENT ON COLUMN product_reviews.order_item_id IS 'Required unique FK to the verified purchase line used by the ownership trigger.';
COMMENT ON COLUMN product_reviews.search_vector IS 'TSVECTOR: title + body. Updated by trigger. GIN-indexed for full-text review search.';

-- Partial index: approved reviews are what customers see (most frequent query path)
CREATE INDEX idx_reviews_approved_by_product
    ON product_reviews (product_id, created_at DESC)
    WHERE is_approved = TRUE;

-- GIN index for full-text review search (search within reviews)
CREATE INDEX idx_reviews_fts
    ON product_reviews USING GIN (search_vector);

-- Admin moderation queue: unapproved reviews ordered by creation time
CREATE INDEX idx_reviews_moderation_queue
    ON product_reviews (created_at ASC)
    WHERE is_approved = FALSE;

-- Per-user review history
CREATE INDEX idx_reviews_user
    ON product_reviews (user_id, created_at DESC);

CREATE INDEX idx_reviews_order_item_fk
    ON product_reviews (order_item_id);

CREATE OR REPLACE FUNCTION fn_validate_verified_review()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN product_variants pv ON pv.id = oi.variant_id
        WHERE oi.id = NEW.order_item_id
          AND o.user_id = NEW.user_id
          AND pv.product_id = NEW.product_id
          AND o.status IN ('delivered', 'refund_requested', 'refund_rejected', 'refunded')
    ) THEN
        RAISE EXCEPTION 'Review must reference a delivered order item owned by the reviewer for this product'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_product_reviews_verify_purchase
    BEFORE INSERT OR UPDATE OF product_id, user_id, order_item_id ON product_reviews
    FOR EACH ROW EXECUTE FUNCTION fn_validate_verified_review();

COMMIT;
