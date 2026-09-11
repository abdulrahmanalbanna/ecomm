-- =============================================================================
-- Migration 009 : Shipping Methods & Shipments
-- Project       : commerce-single-vendor
-- Tables        : shipping_methods, shipments
-- Depends on    : 008_orders.sql (orders)
-- =============================================================================

BEGIN;
-- ---------------------------------------------------------------------------
-- SHIPPING METHODS
-- Admin-configured shipping options shown at checkout.
-- ---------------------------------------------------------------------------
CREATE TABLE shipping_methods (
    id                  BIGSERIAL     PRIMARY KEY,
    name                VARCHAR(100)  UNIQUE NOT NULL,
    carrier             VARCHAR(100),
    estimated_days_min  SMALLINT      CHECK (estimated_days_min >= 0),
    estimated_days_max  SMALLINT      CHECK (estimated_days_max IS NULL OR estimated_days_max >= estimated_days_min),
    base_rate           NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (base_rate >= 0),
    is_active           BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ   NOT NULL DEFAULT now()
);

COMMENT ON TABLE  shipping_methods           IS 'Admin-configured shipping options. Shown at checkout. Seeded in 021_seed_data.sql.';
COMMENT ON COLUMN shipping_methods.base_rate IS 'Base shipping cost in SAR. May be supplemented by weight/dimension rules in application logic.';

CREATE INDEX idx_shipping_methods_active
    ON shipping_methods (id)
    WHERE is_active = TRUE;

CREATE TABLE shipments (
    id                 BIGSERIAL   PRIMARY KEY,
    order_id           BIGINT      NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    shipping_method_id BIGINT      REFERENCES shipping_methods(id) ON DELETE SET NULL,
    tracking_number    VARCHAR(200),
    carrier_name       VARCHAR(100),
    status             VARCHAR(30) NOT NULL DEFAULT 'pending'
                                   CHECK (status IN ('pending', 'dispatched', 'in_transit', 'delivered', 'failed', 'returned')),
    shipped_at         TIMESTAMPTZ,
    estimated_delivery TIMESTAMPTZ,
    delivered_at       TIMESTAMPTZ,
    carrier_response   JSONB,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE  shipments                 IS 'Shipment records. One order can have multiple shipments for partial fulfilment.';
COMMENT ON COLUMN shipments.carrier_response IS 'JSONB: raw carrier API payload. Flexible because carrier response schemas vary.';
COMMENT ON COLUMN shipments.status          IS 'Shipment-specific status, independent from order status.';

CREATE INDEX idx_shipments_order
    ON shipments (order_id);

CREATE INDEX idx_shipments_tracking
    ON shipments (tracking_number)
    WHERE tracking_number IS NOT NULL;

COMMIT;
