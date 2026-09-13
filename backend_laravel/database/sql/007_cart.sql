-- =============================================================================
-- Migration 007 : Shopping Cart & Wishlists
-- Project       : commerce-single-vendor
-- Tables        : carts, cart_items, wishlists
-- Depends on    : 005_products_attributes_variants.sql (products, product_variants)
--                 002_identity_auth.sql (users)
-- Design notes  :
--   - Confirmed: user_id NOT NULL — no guest checkout. All carts are authenticated.
--   - Carts are ephemeral; they expire and are cleaned up by a scheduled job.
--   - Inventory is NOT locked at cart-add time (optimistic read — see §6 architecture).
--   - Lock only happens at order placement (SELECT ... FOR UPDATE on inventory).
-- =============================================================================

BEGIN;

CREATE TABLE carts (
    id          BIGSERIAL    PRIMARY KEY,
    -- Confirmed: user_id NOT NULL (no guest checkout).
    -- UNIQUE: one active cart per user. Previous carts are deleted or merged on checkout.
    user_id     BIGINT       UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    -- Coupon code tentatively applied (validated fully at checkout).
    coupon_code VARCHAR(50),
    -- Cart expires and is eligible for cleanup (via pg_cron or scheduled job).
    -- Abandoned cart recovery sends a notification before this time.
    expires_at  TIMESTAMPTZ,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  carts            IS 'One active cart per authenticated user. Ephemeral — expires and is cleaned up.';
COMMENT ON COLUMN carts.user_id    IS 'UNIQUE: enforces one cart per user. No guest carts (confirmed design decision).';
COMMENT ON COLUMN carts.coupon_code IS 'Tentative coupon. Full validation (active, not expired, usage limits) happens at checkout.';
COMMENT ON COLUMN carts.expires_at  IS 'Expiry for abandoned cart cleanup. Schedule: DELETE FROM carts WHERE expires_at < now().';

-- ---------------------------------------------------------------------------
-- CART ITEMS
-- Individual line items within a cart.
-- ---------------------------------------------------------------------------
CREATE TABLE cart_items (
    id         BIGSERIAL   PRIMARY KEY,
    cart_id    BIGINT      NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
    variant_id BIGINT      NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    quantity   INT         NOT NULL DEFAULT 1 CHECK (quantity > 0),
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Composite unique: same variant cannot appear twice in the same cart.
    -- Application must UPDATE quantity instead of INSERT a duplicate.
    CONSTRAINT uq_cart_items_cart_variant UNIQUE (cart_id, variant_id)
);

COMMENT ON TABLE  cart_items           IS 'Line items in a cart. Same variant cannot appear twice (UNIQUE constraint).';
COMMENT ON COLUMN cart_items.variant_id IS 'ON DELETE CASCADE: removing a variant clears it from all carts (admin must consider stock implications).';
COMMENT ON COLUMN cart_items.quantity  IS 'CHECK (> 0): zero-quantity items must be deleted, not stored.';

CREATE INDEX idx_cart_items_cart_id ON cart_items (cart_id);

-- ---------------------------------------------------------------------------
-- WISHLISTS
-- Saved product items per customer user.
-- ---------------------------------------------------------------------------
CREATE TABLE wishlists (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id BIGINT      NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    variant_id BIGINT      REFERENCES product_variants(id) ON DELETE CASCADE,
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE  wishlists            IS 'Customer saved items / wishlists registry.';
COMMENT ON COLUMN wishlists.user_id    IS 'Owning customer user ID. Cascades on user deletion.';
COMMENT ON COLUMN wishlists.product_id IS 'Saved product ID. Cascades on product deletion.';
COMMENT ON COLUMN wishlists.variant_id IS 'Optional saved variant ID.';

CREATE INDEX idx_wishlists_user_id    ON wishlists (user_id);
CREATE INDEX idx_wishlists_product_id ON wishlists (product_id);

-- Partial unique indexes preventing duplicate wishlist entries per user
CREATE UNIQUE INDEX uq_wishlists_user_product_null_variant
    ON wishlists (user_id, product_id)
    WHERE variant_id IS NULL;

CREATE UNIQUE INDEX uq_wishlists_user_product_variant
    ON wishlists (user_id, product_id, variant_id)
    WHERE variant_id IS NOT NULL;

COMMIT;
