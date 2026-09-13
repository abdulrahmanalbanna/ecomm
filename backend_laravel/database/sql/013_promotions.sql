-- =============================================================================
-- Migration 013 : Promotions — Coupons, Usage Tracking & Banners
-- Project       : commerce-single-vendor
-- Tables        : coupons, coupon_usages, banners
-- Depends on    : 002_identity_auth.sql (users)
--                 008_orders.sql (orders)
--                 001_extensions_enums.sql (citext extension)
-- Design notes  :
--   - CONFIRMED scope: coupon codes and promotional banners.
--   - Coupons support two discount types: percentage (0-100%) or fixed SAR amount.
--   - per_user_limit controls how many times one customer can reuse the same coupon.
--   - coupon_usages tracks individual redemptions for limit enforcement and audit.
--   - used_count incremented atomically via UPDATE ... WHERE used_count < max_uses
--     (0 rows affected = coupon exhausted — application should rollback).
--   - CITEXT on code: 'SAVE10' and 'save10' are treated as identical (prevents bypass).
--   - Banners store promotional imagery, landing page links, position slot, and active schedule.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- COUPONS
-- ---------------------------------------------------------------------------
CREATE TABLE coupons (
    id              BIGSERIAL    PRIMARY KEY,
    -- CITEXT: case-insensitive uniqueness. 'SAVE10' = 'save10' = 'Save10'.
    code            CITEXT       UNIQUE NOT NULL,
    discount_type   VARCHAR(20)  NOT NULL
                    CHECK (discount_type IN ('percentage', 'fixed')),
    -- For 'percentage': value is 0-100. For 'fixed': value is SAR amount.
    discount_value  NUMERIC(10,2) NOT NULL
                    CHECK (discount_value > 0),
    -- Minimum order subtotal (before coupon) required for the coupon to apply.
    -- NULL = no minimum required.
    min_order_amount NUMERIC(12,2) CHECK (min_order_amount IS NULL OR min_order_amount >= 0),
    -- Maximum total uses across all customers. NULL = unlimited.
    max_uses        INT          CHECK (max_uses IS NULL OR max_uses > 0),
    -- Atomically incremented counter of actual redemptions.
    -- CHECK: must never go negative. Enforced by UPDATE WHERE condition, not just CHECK.
    used_count      INT          NOT NULL DEFAULT 0 CHECK (used_count >= 0),
    -- How many times a single user can use this coupon. Default 1 (single use per customer).
    per_user_limit  INT          NOT NULL DEFAULT 1 CHECK (per_user_limit > 0),
    -- Validity window. NULL means open-ended in that direction.
    valid_from      TIMESTAMPTZ,
    valid_until     TIMESTAMPTZ,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- Cross-column constraint: percentage must be between 0 and 100.
    CONSTRAINT chk_coupon_percentage_range
        CHECK (
            (discount_type = 'percentage' AND discount_value > 0 AND discount_value <= 100)
            OR
            (discount_type = 'fixed' AND discount_value > 0)
        ),
    -- Validity window must be logically consistent.
    CONSTRAINT chk_coupon_validity_window
        CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until > valid_from)
);

COMMENT ON TABLE  coupons               IS 'Coupon code registry. Scope: percentage and fixed-amount discounts only (confirmed decision).';
COMMENT ON COLUMN coupons.code          IS 'CITEXT: case-insensitive unique. Prevents SAVE10 vs save10 bypass.';
COMMENT ON COLUMN coupons.discount_type IS 'percentage (0–100%) or fixed (SAR amount). Constrained by chk_coupon_percentage_range.';
COMMENT ON COLUMN coupons.used_count    IS 'Atomically incremented. Application: UPDATE coupons SET used_count = used_count + 1 WHERE id = $1 AND (max_uses IS NULL OR used_count < max_uses). 0 rows = exhausted → ROLLBACK.';
COMMENT ON COLUMN coupons.per_user_limit IS 'Max uses per individual customer. Enforced via coupon_usages table count.';

-- Active coupon lookup at checkout (most frequent coupon query path)
CREATE INDEX idx_coupons_active_code
    ON coupons (code)
    WHERE is_active = TRUE;

-- ---------------------------------------------------------------------------
-- COUPON USAGES
-- Individual redemption records for per-user limit enforcement and audit.
-- ---------------------------------------------------------------------------
CREATE TABLE coupon_usages (
    id         BIGSERIAL   PRIMARY KEY,
    coupon_id  BIGINT      NOT NULL REFERENCES coupons(id) ON DELETE RESTRICT,
    user_id    BIGINT      NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    -- The order on which this coupon was applied.
    -- No FK to partitioned orders table; integrity enforced at application level.
    order_id   BIGINT      NOT NULL,
    used_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- One coupon application per order (business rule: a coupon can't be applied twice to same order).
    CONSTRAINT uq_coupon_usage_per_order UNIQUE (coupon_id, order_id)
);

COMMENT ON TABLE  coupon_usages          IS 'Coupon redemption ledger. Used to enforce per_user_limit and provide audit trail.';
COMMENT ON COLUMN coupon_usages.order_id IS 'Reference to orders.id. No FK (orders is partitioned). Application enforces integrity.';

-- Per-user usage count lookup: SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = $1 AND user_id = $2
CREATE INDEX idx_coupon_usages_user_limit
    ON coupon_usages (coupon_id, user_id);

-- ---------------------------------------------------------------------------
-- BANNERS
-- Promotional banner slides and marketing banners.
-- ---------------------------------------------------------------------------
CREATE TABLE banners (
    id               BIGSERIAL    PRIMARY KEY,
    title            VARCHAR(200) NOT NULL,
    subtitle         TEXT,
    image_url        TEXT         NOT NULL,
    mobile_image_url TEXT,
    link_url         TEXT,
    target_type      VARCHAR(50),
    target_id        VARCHAR(100),
    position         VARCHAR(50)  NOT NULL DEFAULT 'home_slider',
    sort_order       INT          NOT NULL DEFAULT 0,
    is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
    start_at         TIMESTAMPTZ,
    end_at           TIMESTAMPTZ,
    click_count      BIGINT       NOT NULL DEFAULT 0 CHECK (click_count >= 0),
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT banners_sort_order_check CHECK (sort_order >= 0),
    CONSTRAINT banners_validity_window_check
        CHECK (start_at IS NULL OR end_at IS NULL OR end_at > start_at)
);

COMMENT ON TABLE  banners            IS 'Promotional banners and carousel slides.';
COMMENT ON COLUMN banners.position   IS 'Display placement location (e.g. home_slider, sidebar, popup, banner_strip).';
COMMENT ON COLUMN banners.click_count IS 'Total click engagement counter.';

CREATE INDEX idx_banners_position_active ON banners (position, is_active, sort_order);

COMMIT;
