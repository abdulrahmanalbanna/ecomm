-- =============================================================================
-- Migration 008 : Orders, Order Items & Order Events
-- Project       : commerce-single-vendor
-- Tables        : orders, order_items, order_events (RANGE-partitioned)
-- Depends on    : 002_identity_auth.sql (users)
--                 001_extensions_enums.sql (order_status_enum)
--                 005_products_attributes_variants.sql (product_variants)
-- =============================================================================

BEGIN;

-- CREATE SEQUENCE orders_id_seq
--     AS BIGINT
--     START 1
--     INCREMENT 1
--     CACHE 100;
-- ---------------------------------------------------------------------------
-- ORDERS (Standard Table)
-- ---------------------------------------------------------------------------
CREATE TABLE orders (
    id               BIGSERIAL        PRIMARY KEY,
    public_id        UUID             NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    user_id          BIGINT           NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status           order_status_enum NOT NULL DEFAULT 'pending',
    subtotal         NUMERIC(12,2)    NOT NULL CHECK (subtotal >= 0),
    discount_amount  NUMERIC(12,2)    NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
    shipping_amount  NUMERIC(12,2)    NOT NULL DEFAULT 0 CHECK (shipping_amount >= 0),
    tax_amount       NUMERIC(12,2)    NOT NULL DEFAULT 0 CHECK (tax_amount >= 0),
    total_amount     NUMERIC(12,2)    NOT NULL CHECK (total_amount >= 0),
    currency         CHAR(3)          NOT NULL DEFAULT 'SAR',
    coupon_code      VARCHAR(50),
    notes            TEXT,
    shipping_address JSONB            NOT NULL,
    billing_address  JSONB,
    metadata         JSONB            NOT NULL DEFAULT '{}',
    ip_address       INET,
    placed_at        TIMESTAMPTZ      NOT NULL DEFAULT now(),
    confirmed_at     TIMESTAMPTZ,
    shipped_at       TIMESTAMPTZ,
    delivered_at     TIMESTAMPTZ,
    cancelled_at     TIMESTAMPTZ,
    created_at       TIMESTAMPTZ      NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ      NOT NULL DEFAULT now(),
    CONSTRAINT chk_orders_discount_lte_subtotal
        CHECK (discount_amount <= subtotal),
    CONSTRAINT chk_orders_total_formula
        CHECK (total_amount = subtotal - discount_amount + shipping_amount + tax_amount),
    CONSTRAINT chk_orders_shipping_address_object
        CHECK (jsonb_typeof(shipping_address) = 'object'),
    CONSTRAINT chk_orders_billing_address_object
        CHECK (billing_address IS NULL OR jsonb_typeof(billing_address) = 'object'),
    CONSTRAINT chk_orders_metadata_object
        CHECK (jsonb_typeof(metadata) = 'object')
);

COMMENT ON TABLE  orders                  IS 'Order master table.';
COMMENT ON COLUMN orders.public_id        IS 'Customer-facing order reference (in emails, tracking pages). Internal id stays private.';
COMMENT ON COLUMN orders.user_id          IS 'ON DELETE RESTRICT: cannot delete a user who has orders. Soft-delete users instead.';
COMMENT ON COLUMN orders.currency         IS 'SAR — single-currency. Constant value.';
COMMENT ON COLUMN orders.shipping_address IS 'JSONB snapshot of address at order placement time. Source of truth for fulfillment.';
COMMENT ON COLUMN orders.metadata         IS 'Free-form JSONB for gateway hints, A/B test variants, or integration flags.';

CREATE INDEX idx_orders_user_history
    ON orders (user_id, placed_at DESC);

CREATE INDEX idx_orders_status
    ON orders (status, placed_at DESC);

-- Partial index: active orders only (admin processing queue — frequent dashboard query)
CREATE INDEX idx_orders_active
    ON orders (status, placed_at DESC)
    WHERE status IN ('pending', 'payment_pending', 'confirmed', 'processing');

-- BRIN index for time-range scans (very low overhead; append-mostly table)
CREATE INDEX idx_orders_placed_at_brin
    ON orders USING BRIN (placed_at);

-- Covering index for customer order history page (avoids heap fetch)
CREATE INDEX idx_orders_user_cover
    ON orders (user_id, placed_at DESC)
    INCLUDE (status, total_amount, public_id);

-- ---------------------------------------------------------------------------
-- ORDER ITEMS
-- Line items for each order. Stores a full product snapshot at purchase time.
-- ---------------------------------------------------------------------------
CREATE TABLE order_items (
    id               BIGSERIAL     PRIMARY KEY,
    order_id         BIGINT        NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    variant_id       BIGINT        NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    product_snapshot JSONB         NOT NULL,
    sku              VARCHAR(100)  NOT NULL,
    name             TEXT          NOT NULL,
    quantity         INT           NOT NULL CHECK (quantity > 0),
    unit_price       NUMERIC(12,2) NOT NULL CHECK (unit_price >= 0),
    total_price      NUMERIC(12,2) NOT NULL CHECK (
        total_price >= 0 AND total_price = quantity * unit_price
    ),
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT now()
);

COMMENT ON TABLE  order_items                IS 'Immutable line items. product_snapshot preserves state at purchase time.';
COMMENT ON COLUMN order_items.product_snapshot IS 'JSONB snapshot of product + variant at purchase time. Source of truth for returns, disputes, reporting.';
COMMENT ON COLUMN order_items.unit_price      IS 'Price at order time. May differ from product_variants.price (price changes after order).';
COMMENT ON COLUMN order_items.total_price     IS 'quantity * unit_price. Stored for query performance.';

CREATE INDEX idx_order_items_order
    ON order_items (order_id);

-- Used for: "how many units of variant X were sold?" (sales analytics)
CREATE INDEX idx_order_items_variant
    ON order_items (variant_id);

-- ---------------------------------------------------------------------------
-- ORDER EVENTS (PARTITIONED — RANGE by created_at, monthly)
-- Append-only state machine audit trail. Every status transition creates one row.
-- ---------------------------------------------------------------------------
CREATE SEQUENCE order_events_id_seq
    AS BIGINT
    START 1
    INCREMENT 1
    CACHE 100;

CREATE TABLE order_events (
    id              BIGINT            NOT NULL DEFAULT nextval('order_events_id_seq'),
    order_id        BIGINT            NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    from_status     order_status_enum,
    to_status       order_status_enum NOT NULL,
    triggered_by    BIGINT            REFERENCES users(id) ON DELETE SET NULL,
    note            TEXT,
    -- Extra data: gateway callback payload, webhook body, admin reason, etc.
    metadata        JSONB,
    created_at      TIMESTAMPTZ       NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

COMMENT ON TABLE  order_events              IS 'Append-only order lifecycle event log.';
COMMENT ON COLUMN order_events.from_status  IS 'NULL for the initial pending event (no prior status). Always populated for subsequent transitions.';
COMMENT ON COLUMN order_events.triggered_by IS 'User who triggered the transition. NULL if triggered by system/webhook.';
COMMENT ON COLUMN order_events.metadata     IS 'JSONB: gateway callbacks, refund reason, courier tracking — context-dependent.';


-- Create monthly partitions for 2025–2028.
DO $$
DECLARE
    y       INT;
    m       INT;
    p_start TIMESTAMPTZ;
    p_end   TIMESTAMPTZ;
    tname   TEXT;
BEGIN
    FOR y IN 2025..2028 LOOP
        FOR m IN 1..12 LOOP
            p_start := make_timestamptz(y, m, 1, 0, 0, 0, 'UTC');
            p_end   := p_start + INTERVAL '1 month';
            tname   := format('order_events_%s_%s', y, lpad(m::TEXT, 2, '0'));
            EXECUTE format(
                'CREATE TABLE IF NOT EXISTS %I
                 PARTITION OF order_events
                 FOR VALUES FROM (%L) TO (%L)',
                tname, p_start, p_end
            );
        END LOOP;
    END LOOP;
END;
$$;

CREATE TABLE order_events_default PARTITION OF order_events DEFAULT;

CREATE INDEX idx_order_events_brin
    ON order_events USING BRIN (created_at);

CREATE INDEX idx_order_events_order
    ON order_events (order_id, created_at DESC);

COMMIT;
