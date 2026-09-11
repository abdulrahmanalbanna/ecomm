-- =============================================================================
-- Migration 006 : Inventory, Reservations & Stock Management
-- Project       : commerce-single-vendor
-- Tables        : inventory, inventory_reservations, inventory_movements (RANGE-partitioned monthly, 2025–2028)
-- Depends on    : 005_products_attributes_variants.sql (product_variants, users)
-- Concurrency   : Architecture §6 — SELECT ... FOR UPDATE on inventory row
--                 at order placement prevents overselling and race conditions.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. INVENTORY
-- 1:1 with product_variants. Tracks real-time stock levels.
-- ---------------------------------------------------------------------------
CREATE TABLE inventory (
    id                   BIGSERIAL   PRIMARY KEY,
    -- UNIQUE enforces 1:1 relationship with product_variants at DB level.
    variant_id           BIGINT      UNIQUE NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    -- Physical stock count on shelves/warehouse.
    quantity_on_hand     INT         NOT NULL DEFAULT 0 CHECK (quantity_on_hand >= 0),
    -- Stock held for pending/confirmed orders not yet physically deducted.
    quantity_reserved    INT         NOT NULL DEFAULT 0 CHECK (quantity_reserved >= 0),
    -- Explicit backordered stock portion across active reservations.
    quantity_backordered INT         NOT NULL DEFAULT 0 CHECK (quantity_backordered >= 0),
    -- GENERATED column: available = on_hand - (reserved - backordered).
    quantity_available   INT         GENERATED ALWAYS AS (quantity_on_hand - (quantity_reserved - quantity_backordered)) STORED,
    -- Low-stock alert threshold. Admin is notified when quantity_available <= reorder_point.
    reorder_point        INT         NOT NULL DEFAULT 5  CHECK (reorder_point >= 0),
    reorder_quantity     INT         NOT NULL DEFAULT 20 CHECK (reorder_quantity > 0),
    -- If TRUE: orders allowed even when quantity_available = 0 (pre-orders / backorders).
    allow_backorder      BOOLEAN     NOT NULL DEFAULT FALSE,
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    
    CONSTRAINT chk_inventory_reserved_lte_onhand
        CHECK (allow_backorder OR quantity_reserved <= quantity_on_hand),
    CONSTRAINT chk_inventory_backordered_lte_reserved
        CHECK (quantity_backordered <= quantity_reserved)
);

COMMENT ON TABLE  inventory                     IS '1:1 with product_variants. Use SELECT ... FOR UPDATE during order placement to serialize concurrent stock deductions.';
COMMENT ON COLUMN inventory.quantity_on_hand    IS 'Physical stock. CHECK (>= 0) is the final DB guard.';
COMMENT ON COLUMN inventory.quantity_reserved   IS 'Stock committed to pending/confirmed orders. Released on cancellation.';
COMMENT ON COLUMN inventory.quantity_backordered IS 'Explicit backorder quantity for orders placed beyond physical on_hand stock.';
COMMENT ON COLUMN inventory.quantity_available  IS 'GENERATED: on_hand - (reserved - backordered). Always consistent.';
COMMENT ON COLUMN inventory.allow_backorder     IS 'If TRUE: orders proceed even at quantity_available = 0 (pre-order/backorder model).';

-- Partial index: in-stock filter for catalog and search results.
CREATE INDEX idx_inventory_in_stock
    ON inventory (variant_id)
    WHERE quantity_available > 0;

-- Low-stock alert index for admin dashboard
CREATE INDEX idx_inventory_low_stock
    ON inventory (variant_id, quantity_available, reorder_point)
    WHERE quantity_available <= reorder_point AND quantity_available > 0;

-- ---------------------------------------------------------------------------
-- 2. INVENTORY_RESERVATIONS
-- Explicit stock reservation per order item. Enforces lifecycle status.
-- ---------------------------------------------------------------------------
CREATE TABLE inventory_reservations (
    id                   BIGSERIAL   PRIMARY KEY,
    order_id             BIGINT      NOT NULL,
    variant_id           BIGINT      NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    quantity             INT         NOT NULL CHECK (quantity > 0),
    quantity_backordered INT         NOT NULL DEFAULT 0 CHECK (quantity_backordered >= 0 AND quantity_backordered <= quantity),
    status               VARCHAR(20) NOT NULL DEFAULT 'active'
                         CHECK (status IN ('active', 'released', 'expired', 'cancelled', 'converted')),
    created_by           BIGINT      REFERENCES users(id) ON DELETE SET NULL,
    expires_at           TIMESTAMPTZ,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE  inventory_reservations                      IS 'Explicit stock reservation per order item. Enforces lifecycle status and tracks backordered quantities.';
COMMENT ON COLUMN inventory_reservations.order_id             IS 'Reference to orders.id (partitioned).';
COMMENT ON COLUMN inventory_reservations.variant_id           IS 'Reference to product_variants.id.';
COMMENT ON COLUMN inventory_reservations.quantity             IS 'Total reserved quantity for this order item.';
COMMENT ON COLUMN inventory_reservations.quantity_backordered IS 'Explicit backordered quantity for this reservation.';
COMMENT ON COLUMN inventory_reservations.status               IS 'active | released | expired | cancelled | converted';

-- Unique partial index: maximum ONE active reservation per (order_id, variant_id)
CREATE UNIQUE INDEX uq_active_inventory_reservation
    ON inventory_reservations (order_id, variant_id)
    WHERE status = 'active';

CREATE INDEX idx_inventory_reservations_order
    ON inventory_reservations (order_id);

CREATE INDEX idx_inventory_reservations_variant
    ON inventory_reservations (variant_id);

CREATE INDEX idx_inventory_reservations_expired
    ON inventory_reservations (expires_at)
    WHERE status = 'active' AND expires_at IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 3. INVENTORY_MOVEMENTS (PARTITIONED — RANGE by created_at, monthly)
-- Append-only stock movement ledger. Every stock change creates one row.
-- ---------------------------------------------------------------------------

CREATE SEQUENCE inventory_movements_id_seq
    AS BIGINT
    START 1
    INCREMENT 1
    CACHE 100;

CREATE TABLE inventory_movements (
    id                      BIGINT       NOT NULL DEFAULT nextval('inventory_movements_id_seq'),
    variant_id              BIGINT       NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    order_id                BIGINT,
    reservation_id          BIGINT       REFERENCES inventory_reservations(id) ON DELETE SET NULL,
    movement_type           VARCHAR(30)  NOT NULL
                            CHECK (movement_type IN (
                                'purchase',    -- Stock received from supplier
                                'sale',        -- Deducted on confirmed order delivery
                                'return',      -- Restocked from customer return
                                'adjustment',  -- Manual admin correction
                                'reservation', -- Reserved at order placement
                                'release'      -- Released when order cancelled/expired
                            )),
    quantity_delta          INT          NOT NULL,
    quantity_on_hand_delta  INT          NOT NULL DEFAULT 0,
    quantity_reserved_delta INT          NOT NULL DEFAULT 0,
    on_hand_after           INT          NOT NULL,
    quantity_on_hand_after  INT          NOT NULL DEFAULT 0,
    quantity_reserved_after INT          NOT NULL DEFAULT 0,
    note                    TEXT,
    created_by              BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

COMMENT ON TABLE inventory_movements IS 'Append-only stock movement ledger. Partitioned monthly by created_at.';

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
            tname   := format('inventory_movements_%s_%s', y, lpad(m::TEXT, 2, '0'));
            EXECUTE format(
                'CREATE TABLE IF NOT EXISTS %I
                 PARTITION OF inventory_movements
                 FOR VALUES FROM (%L) TO (%L)',
                tname, p_start, p_end
            );
        END LOOP;
    END LOOP;
END;
$$;

-- Default partition
CREATE TABLE inventory_movements_default PARTITION OF inventory_movements DEFAULT;

-- BRIN index for append-only time-series
CREATE INDEX idx_inv_movements_brin_created
    ON inventory_movements USING BRIN (created_at);

-- B-Tree index for variant history
CREATE INDEX idx_inv_movements_variant_time
    ON inventory_movements (variant_id, created_at DESC);

-- Ledger Immutability Trigger
CREATE OR REPLACE FUNCTION fn_block_inventory_movement_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'inventory_movements is an append-only ledger. UPDATE and DELETE operations are forbidden.'
        USING ERRCODE = '55000';
END;
$$;

DROP TRIGGER IF EXISTS trg_block_inventory_movement_update ON inventory_movements;
CREATE TRIGGER trg_block_inventory_movement_update
    BEFORE UPDATE OR DELETE ON inventory_movements
    FOR EACH ROW
    EXECUTE FUNCTION fn_block_inventory_movement_mutation();

COMMIT;
