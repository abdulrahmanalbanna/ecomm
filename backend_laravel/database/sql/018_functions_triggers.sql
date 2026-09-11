-- =============================================================================
-- Migration 018 : Functions & Triggers
-- Project       : commerce-single-vendor
-- Depends on    : All prior table migrations
-- Contents      :
--   1. fn_set_updated_at()               — Universal updated_at maintenance trigger
--   2. fn_update_product_search_vector() — TSVECTOR maintenance for products
--   3. fn_update_review_search_vector()  — TSVECTOR maintenance for product_reviews
--   4. fn_validate_order_status_transition() — Order state machine guard trigger
--      (includes refund_requested → refund_rejected; refund_rejected is terminal)
--   5. fn_release_inventory_on_cancel()  — Inventory release helper (called by application)
--   6. Trigger instantiations on all relevant tables
--   7. fn_audit_variant_price_change()   — Variant price edit audit trail
--   8. fn_validate_payment_status_transition() — Payment state machine guard trigger
-- =============================================================================

BEGIN;

-- ===========================================================================
-- 1. UNIVERSAL updated_at TRIGGER FUNCTION
-- Attach to every table that has an updated_at column via BEFORE UPDATE trigger.
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_set_updated_at()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_set_updated_at() IS
    'Universal BEFORE UPDATE trigger function. Sets updated_at = now() on every row modification. '
    'Attach with: CREATE TRIGGER trg_<table>_updated_at BEFORE UPDATE ON <table> '
    'FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();';

-- Attach to every table with an updated_at column
DROP TRIGGER IF EXISTS trg_users_updated_at ON users;
CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_customer_profiles_updated_at ON customer_profiles;
CREATE TRIGGER trg_customer_profiles_updated_at
    BEFORE UPDATE ON customer_profiles
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_addresses_updated_at ON addresses;
CREATE TRIGGER trg_addresses_updated_at
    BEFORE UPDATE ON addresses
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_categories_updated_at ON categories;
CREATE TRIGGER trg_categories_updated_at
    BEFORE UPDATE ON categories
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_products_updated_at ON products;
CREATE TRIGGER trg_products_updated_at
    BEFORE UPDATE ON products
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_product_variants_updated_at ON product_variants;
CREATE TRIGGER trg_product_variants_updated_at
    BEFORE UPDATE ON product_variants
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_inventory_updated_at ON inventory;
CREATE TRIGGER trg_inventory_updated_at
    BEFORE UPDATE ON inventory
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_carts_updated_at ON carts;
CREATE TRIGGER trg_carts_updated_at
    BEFORE UPDATE ON carts
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- Trigger on partitioned orders table (PostgreSQL 13+: trigger on parent applies to all partitions)
DROP TRIGGER IF EXISTS trg_orders_updated_at ON orders;
CREATE TRIGGER trg_orders_updated_at
    BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_shipping_methods_updated_at ON shipping_methods;
CREATE TRIGGER trg_shipping_methods_updated_at
    BEFORE UPDATE ON shipping_methods
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_shipments_updated_at ON shipments;
CREATE TRIGGER trg_shipments_updated_at
    BEFORE UPDATE ON shipments
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_payment_gateways_updated_at ON payment_gateways;
CREATE TRIGGER trg_payment_gateways_updated_at
    BEFORE UPDATE ON payment_gateways
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_payments_updated_at ON payments;
CREATE TRIGGER trg_payments_updated_at
    BEFORE UPDATE ON payments
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_installment_plans_updated_at ON installment_plans;
CREATE TRIGGER trg_installment_plans_updated_at
    BEFORE UPDATE ON installment_plans
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_installments_updated_at ON installments;
CREATE TRIGGER trg_installments_updated_at
    BEFORE UPDATE ON installments
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_refunds_updated_at ON refunds;
CREATE TRIGGER trg_refunds_updated_at
    BEFORE UPDATE ON refunds
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_coupons_updated_at ON coupons;
CREATE TRIGGER trg_coupons_updated_at
    BEFORE UPDATE ON coupons
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

DROP TRIGGER IF EXISTS trg_product_reviews_updated_at ON product_reviews;
CREATE TRIGGER trg_product_reviews_updated_at
    BEFORE UPDATE ON product_reviews
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- ===========================================================================
-- 2. PRODUCT FULL-TEXT SEARCH VECTOR
-- Maintains products.search_vector automatically on INSERT and UPDATE.
-- Weights: A=name (highest), B=brand, C=short_description, D=description (lowest)
-- Using 'simple' text search config: works for all languages including Arabic.
-- Phase 2: Replace 'simple' with a language-specific config or pg_trgm for Arabic FTS.
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_update_product_search_vector()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('simple', COALESCE(NEW.name, '')),              'A') ||
        setweight(to_tsvector('simple', COALESCE(NEW.brand, '')),             'B') ||
        setweight(to_tsvector('simple', COALESCE(NEW.short_description, '')), 'C') ||
        setweight(to_tsvector('simple', COALESCE(NEW.description, '')),       'D');
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_update_product_search_vector() IS
    'Maintains products.search_vector on INSERT/UPDATE. '
    'Uses simple config (language-agnostic). '
    'Phase 2: Replace with Arabic text search config or pg_trgm for richer Arabic FTS. '
    'Weights: A=name, B=brand, C=short_description, D=description.';

DROP TRIGGER IF EXISTS trg_products_search_vector ON products;
CREATE TRIGGER trg_products_search_vector
    BEFORE INSERT OR UPDATE OF name, brand, short_description, description
    ON products
    FOR EACH ROW EXECUTE FUNCTION fn_update_product_search_vector();

-- ===========================================================================
-- 3. PRODUCT REVIEW FULL-TEXT SEARCH VECTOR
-- Maintains product_reviews.search_vector automatically.
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_update_review_search_vector()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('simple', COALESCE(NEW.title, '')), 'A') ||
        setweight(to_tsvector('simple', COALESCE(NEW.body, '')),  'B');
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_update_review_search_vector() IS
    'Maintains product_reviews.search_vector on INSERT/UPDATE of title or body. '
    'Weights: A=title, B=body.';

DROP TRIGGER IF EXISTS trg_reviews_search_vector ON product_reviews;
CREATE TRIGGER trg_reviews_search_vector
    BEFORE INSERT OR UPDATE OF title, body
    ON product_reviews
    FOR EACH ROW EXECUTE FUNCTION fn_update_review_search_vector();

-- ===========================================================================
-- 4. ORDER STATUS TRANSITION GUARD
-- Enforces the order lifecycle state machine at the database level.
-- Prevents invalid status transitions even if application code has a bug.
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_validate_order_status_transition()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    -- Allowed transitions per source status.
    -- Key  = current (OLD) status.
    -- Value = array of valid next statuses.
    allowed JSONB := '{
        "pending":          ["payment_pending", "cancelled", "failed"],
        "payment_pending":  ["confirmed", "failed", "cancelled"],
        "confirmed":        ["processing", "cancelled"],
        "processing":       ["shipped", "cancelled"],
        "shipped":          ["delivered"],
        "delivered":        ["refund_requested"],
        "refund_requested": ["refunded", "refund_rejected"],
        "refund_rejected":  [],
        "cancelled":        [],
        "refunded":         [],
        "failed":           []
    }';
    valid_next TEXT[];
BEGIN
    -- No-op: status unchanged (UPDATE of other columns)
    IF OLD.status = NEW.status THEN
        RETURN NEW;
    END IF;

    -- Extract valid next statuses for the current state
    SELECT ARRAY(
        SELECT jsonb_array_elements_text(allowed -> OLD.status::TEXT)
    ) INTO valid_next;

    -- Reject transition if target status is not in the allowed list
    IF NOT (NEW.status::TEXT = ANY(valid_next)) THEN
        RAISE EXCEPTION
            'Invalid order status transition: % → %. Allowed transitions from %: [%]',
            OLD.status, NEW.status, OLD.status, array_to_string(valid_next, ', ')
            USING ERRCODE = 'P0001';
    END IF;

    -- Set the lifecycle timestamp for the new status automatically
    CASE NEW.status
        WHEN 'confirmed'        THEN NEW.confirmed_at  = COALESCE(NEW.confirmed_at,  now());
        WHEN 'shipped'          THEN NEW.shipped_at    = COALESCE(NEW.shipped_at,    now());
        WHEN 'delivered'        THEN NEW.delivered_at  = COALESCE(NEW.delivered_at,  now());
        WHEN 'cancelled'        THEN NEW.cancelled_at  = COALESCE(NEW.cancelled_at,  now());
        ELSE NULL;
    END CASE;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_validate_order_status_transition() IS
    'BEFORE UPDATE trigger on orders. Enforces order state machine transitions and '
    'auto-sets lifecycle timestamps (confirmed_at, shipped_at, delivered_at, cancelled_at). '
    'Raises P0001 on invalid transition. This is a DB-level safety net — application '
    'should also validate transitions before issuing the UPDATE. '
    'refund_requested → refund_rejected is the explicit denial path; refund_rejected '
    'is TERMINAL — further dispute handling is an application-level process.';

-- Trigger on partitioned orders (fires before any UPDATE on orders or its partitions)
DROP TRIGGER IF EXISTS trg_orders_status_transition ON orders;
CREATE TRIGGER trg_orders_status_transition
    BEFORE UPDATE OF status ON orders
    FOR EACH ROW EXECUTE FUNCTION fn_validate_order_status_transition();

-- ===========================================================================
-- 5. DEFERRED ORDER / LINE-ITEM RECONCILIATION
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_validate_order_item_subtotal()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_order_id        BIGINT;
    v_subtotal        NUMERIC(12,2);
    v_items_total     NUMERIC(12,2);
    v_old_subtotal    NUMERIC(12,2);
    v_old_items_total NUMERIC(12,2);
BEGIN
    IF TG_OP = 'DELETE' THEN
        v_order_id := OLD.order_id;
    ELSE
        v_order_id := NEW.order_id;
    END IF;

    SELECT subtotal INTO v_subtotal
    FROM orders
    WHERE id = v_order_id;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(SUM(total_price), 0) INTO v_items_total
    FROM order_items
    WHERE order_id = v_order_id;

    IF v_subtotal <> v_items_total THEN
        RAISE EXCEPTION 'Order subtotal % does not equal line-item total % for order %',
            v_subtotal, v_items_total, v_order_id
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.order_id IS DISTINCT FROM NEW.order_id THEN
        SELECT subtotal INTO v_old_subtotal
        FROM orders
        WHERE id = OLD.order_id;

        IF FOUND THEN
            SELECT COALESCE(SUM(total_price), 0) INTO v_old_items_total
            FROM order_items
            WHERE order_id = OLD.order_id;

            IF v_old_subtotal <> v_old_items_total THEN
                RAISE EXCEPTION 'Order subtotal % does not equal line-item total % for order %',
                    v_old_subtotal, v_old_items_total, OLD.order_id
                    USING ERRCODE = '23514';
            END IF;
        END IF;
    END IF;
    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION fn_validate_order_subtotal()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_items_total NUMERIC(12,2);
BEGIN
    SELECT COALESCE(SUM(total_price), 0) INTO v_items_total
    FROM order_items
    WHERE order_id = NEW.id;

    IF NEW.subtotal <> v_items_total THEN
        RAISE EXCEPTION 'Order subtotal % does not equal line-item total % for order %',
            NEW.subtotal, v_items_total, NEW.id
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS trg_order_items_reconcile_subtotal ON order_items;
CREATE CONSTRAINT TRIGGER trg_order_items_reconcile_subtotal
    AFTER INSERT OR UPDATE OR DELETE ON order_items
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION fn_validate_order_item_subtotal();

DROP TRIGGER IF EXISTS trg_orders_reconcile_subtotal ON orders;
CREATE CONSTRAINT TRIGGER trg_orders_reconcile_subtotal
    AFTER INSERT OR UPDATE OF subtotal ON orders
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION fn_validate_order_subtotal();

-- ===========================================================================
-- 6. INVENTORY RESERVATION & LIFECYCLE STORED FUNCTIONS
-- Centralises row locking protocols, CHECK enforcement, and explicit reservation state.
-- ===========================================================================

-- Single item reservation (v2)
CREATE OR REPLACE FUNCTION fn_reserve_inventory_v2(
    p_order_id    BIGINT,
    p_variant_id  BIGINT,
    p_quantity    INT,
    p_actor_id    BIGINT DEFAULT NULL,
    p_expires_at  TIMESTAMPTZ DEFAULT NULL
)
RETURNS BIGINT
LANGUAGE plpgsql
AS $$
DECLARE
    v_available       INT;
    v_allow_back      BOOLEAN;
    v_on_hand_now     INT;
    v_reserved_now    INT;
    v_backorder_qty   INT := 0;
    v_reservation_id  BIGINT;
    v_existing_res_id BIGINT;
    v_existing_qty    INT;
BEGIN
    IF p_quantity IS NULL OR p_quantity <= 0 THEN
        RAISE EXCEPTION 'Inventory quantity must be positive; got %', p_quantity
            USING ERRCODE = '22003';
    END IF;

    IF p_order_id IS NULL THEN
        RAISE EXCEPTION 'Order ID is required for inventory reservation'
            USING ERRCODE = '22004';
    END IF;

    -- Lock inventory row
    PERFORM id FROM inventory WHERE variant_id = p_variant_id FOR UPDATE;

    SELECT quantity_available, allow_backorder, quantity_on_hand, quantity_reserved
    INTO v_available, v_allow_back, v_on_hand_now, v_reserved_now
    FROM inventory
    WHERE variant_id = p_variant_id;

    IF v_available IS NULL THEN
        RAISE EXCEPTION 'Inventory record not found for variant_id = %', p_variant_id
            USING ERRCODE = 'P0002';
    END IF;

    IF NOT v_allow_back AND v_available < p_quantity THEN
        RAISE EXCEPTION 'Insufficient stock for variant_id = %. Available: %, Requested: %',
            p_variant_id, v_available, p_quantity
            USING ERRCODE = 'P0003';
    END IF;

    -- Calculate explicit backordered quantity for this reservation
    IF v_available < p_quantity THEN
        IF v_available > 0 THEN
            v_backorder_qty := p_quantity - v_available;
        ELSE
            v_backorder_qty := p_quantity;
        END IF;
    END IF;

    -- Check active reservation duplicate / retry idempotency
    SELECT id, quantity INTO v_existing_res_id, v_existing_qty
    FROM inventory_reservations
    WHERE order_id = p_order_id AND variant_id = p_variant_id AND status = 'active';

    IF v_existing_res_id IS NOT NULL THEN
        IF v_existing_qty = p_quantity THEN
            -- Idempotent checkout retry: return existing active reservation ID without double-reserving stock
            RETURN v_existing_res_id;
        ELSE
            RAISE EXCEPTION 'Conflicting reservation quantity for order_id = % and variant_id = %. Existing: %, Requested: %',
                p_order_id, p_variant_id, v_existing_qty, p_quantity
                USING ERRCODE = 'P0005';
        END IF;
    END IF;

    -- Update inventory reserved & backordered quantities
    UPDATE inventory
    SET quantity_reserved    = quantity_reserved + p_quantity,
        quantity_backordered = quantity_backordered + v_backorder_qty,
        updated_at           = now()
    WHERE variant_id = p_variant_id;

    -- Create reservation record with explicit quantity_backordered
    INSERT INTO inventory_reservations (
        order_id, variant_id, quantity, quantity_backordered, status, created_by, expires_at, created_at, updated_at
    ) VALUES (
        p_order_id, p_variant_id, p_quantity, v_backorder_qty, 'active', p_actor_id, p_expires_at, now(), now()
    ) RETURNING id INTO v_reservation_id;

    -- Write ledger row
    INSERT INTO inventory_movements (
        variant_id, order_id, reservation_id, movement_type,
        quantity_delta, quantity_on_hand_delta, quantity_reserved_delta,
        on_hand_after, quantity_on_hand_after, quantity_reserved_after,
        created_by
    ) VALUES (
        p_variant_id, p_order_id, v_reservation_id, 'reservation',
        -p_quantity, 0, p_quantity,
        v_on_hand_now, v_on_hand_now, v_reserved_now + p_quantity,
        p_actor_id
    );

    RETURN v_reservation_id;
END;
$$;


-- Batch reservation (v2)
CREATE OR REPLACE FUNCTION fn_reserve_inventory_batch_v2(
    p_order_id    BIGINT,
    p_variant_ids BIGINT[],
    p_quantities  INT[],
    p_actor_id    BIGINT DEFAULT NULL,
    p_expires_at  TIMESTAMPTZ DEFAULT NULL
)
RETURNS BIGINT[]
LANGUAGE plpgsql
AS $$
DECLARE
    v_len             INT;
    v_i               INT;
    v_res_id          BIGINT;
    v_res_ids         BIGINT[] := '{}';
BEGIN
    v_len := array_length(p_variant_ids, 1);
    IF v_len IS NULL OR v_len = 0 THEN
        RETURN v_res_ids;
    END IF;

    IF p_quantities IS NULL OR array_length(p_quantities, 1) <> v_len THEN
        RAISE EXCEPTION 'Variant IDs array length (%) does not match quantities array length (%)',
            v_len, COALESCE(array_length(p_quantities, 1), 0)
            USING ERRCODE = 'P0004';
    END IF;

    IF p_order_id IS NULL THEN
        RAISE EXCEPTION 'Order ID is required for inventory batch reservation'
            USING ERRCODE = '22004';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM unnest(p_variant_ids) AS ids(variant_id)
        GROUP BY variant_id
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Duplicate variant IDs are not allowed in batch reservation'
            USING ERRCODE = 'P0004';
    END IF;

    -- Lock all inventory rows in variant_id ASC order to avoid deadlocks
    PERFORM id FROM inventory
    WHERE variant_id = ANY(p_variant_ids)
    ORDER BY variant_id ASC
    FOR UPDATE;

    FOR v_i IN 1..v_len LOOP
        v_res_id := fn_reserve_inventory_v2(
            p_order_id,
            p_variant_ids[v_i],
            p_quantities[v_i],
            p_actor_id,
            p_expires_at
        );
        v_res_ids := array_append(v_res_ids, v_res_id);
    END LOOP;

    RETURN v_res_ids;
END;
$$;


-- Release inventory reservation
CREATE OR REPLACE FUNCTION fn_release_inventory_reservation(
    p_reservation_id BIGINT,
    p_new_status     VARCHAR DEFAULT 'released',
    p_actor_id       BIGINT DEFAULT NULL
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_order_id       BIGINT;
    v_variant_id     BIGINT;
    v_quantity       INT;
    v_backordered    INT;
    v_curr_status    VARCHAR;
    v_on_hand_now    INT;
    v_reserved_now   INT;
BEGIN
    IF p_new_status NOT IN ('released', 'cancelled', 'expired') THEN
        RAISE EXCEPTION 'Invalid target release status: %', p_new_status
            USING ERRCODE = '22023';
    END IF;

    SELECT order_id, variant_id, quantity, quantity_backordered, status
    INTO v_order_id, v_variant_id, v_quantity, v_backordered, v_curr_status
    FROM inventory_reservations
    WHERE id = p_reservation_id
    FOR UPDATE;

    IF v_curr_status IS NULL THEN
        RAISE EXCEPTION 'Reservation record not found for id = %', p_reservation_id
            USING ERRCODE = 'P0002';
    END IF;

    IF v_curr_status <> 'active' THEN
        RAISE EXCEPTION 'Cannot release reservation %: status is already ''%''', p_reservation_id, v_curr_status
            USING ERRCODE = 'P0006';
    END IF;

    -- Lock inventory row
    PERFORM id FROM inventory WHERE variant_id = v_variant_id FOR UPDATE;

    SELECT quantity_on_hand, quantity_reserved
    INTO v_on_hand_now, v_reserved_now
    FROM inventory WHERE variant_id = v_variant_id;

    -- Update inventory reserved & backordered counts
    UPDATE inventory
    SET quantity_reserved    = GREATEST(0, quantity_reserved - v_quantity),
        quantity_backordered = GREATEST(0, quantity_backordered - v_backordered),
        updated_at           = now()
    WHERE variant_id = v_variant_id;

    -- Update reservation status
    UPDATE inventory_reservations
    SET status = p_new_status,
        updated_at = now()
    WHERE id = p_reservation_id;

    -- Write ledger row
    INSERT INTO inventory_movements (
        variant_id, order_id, reservation_id, movement_type,
        quantity_delta, quantity_on_hand_delta, quantity_reserved_delta,
        on_hand_after, quantity_on_hand_after, quantity_reserved_after,
        created_by
    ) VALUES (
        v_variant_id, v_order_id, p_reservation_id, 'release',
        v_quantity, 0, -v_quantity,
        v_on_hand_now, v_on_hand_now, GREATEST(0, v_reserved_now - v_quantity),
        p_actor_id
    );
END;
$$;


-- Convert inventory reservation (on order delivered)
CREATE OR REPLACE FUNCTION fn_convert_inventory_reservation(
    p_reservation_id BIGINT,
    p_actor_id       BIGINT DEFAULT NULL
)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_order_id        BIGINT;
    v_variant_id      BIGINT;
    v_quantity        INT;
    v_backordered     INT;
    v_curr_status     VARCHAR;
    v_on_hand_now     INT;
    v_reserved_now    INT;
    v_physical_deduct INT;
BEGIN
    SELECT order_id, variant_id, quantity, quantity_backordered, status
    INTO v_order_id, v_variant_id, v_quantity, v_backordered, v_curr_status
    FROM inventory_reservations
    WHERE id = p_reservation_id
    FOR UPDATE;

    IF v_curr_status IS NULL THEN
        RAISE EXCEPTION 'Reservation record not found for id = %', p_reservation_id
            USING ERRCODE = 'P0002';
    END IF;

    IF v_curr_status <> 'active' THEN
        RAISE EXCEPTION 'Cannot convert reservation %: status is already ''%''', p_reservation_id, v_curr_status
            USING ERRCODE = 'P0007';
    END IF;

    -- Lock inventory row
    PERFORM id FROM inventory WHERE variant_id = v_variant_id FOR UPDATE;

    SELECT quantity_on_hand, quantity_reserved
    INTO v_on_hand_now, v_reserved_now
    FROM inventory WHERE variant_id = v_variant_id;

    -- Physical stock to deduct from on_hand is the non-backordered portion
    v_physical_deduct := GREATEST(0, v_quantity - v_backordered);

    -- Update physical stock on_hand, reserved, and backordered
    UPDATE inventory
    SET quantity_on_hand     = GREATEST(0, quantity_on_hand - v_physical_deduct),
        quantity_reserved    = GREATEST(0, quantity_reserved - v_quantity),
        quantity_backordered = GREATEST(0, quantity_backordered - v_backordered),
        updated_at           = now()
    WHERE variant_id = v_variant_id;

    -- Update reservation status to converted
    UPDATE inventory_reservations
    SET status = 'converted',
        updated_at = now()
    WHERE id = p_reservation_id;

    -- Write ledger row for sale
    INSERT INTO inventory_movements (
        variant_id, order_id, reservation_id, movement_type,
        quantity_delta, quantity_on_hand_delta, quantity_reserved_delta,
        on_hand_after, quantity_on_hand_after, quantity_reserved_after,
        created_by
    ) VALUES (
        v_variant_id, v_order_id, p_reservation_id, 'sale',
        -v_quantity, -v_physical_deduct, -v_quantity,
        v_on_hand_now - v_physical_deduct, v_on_hand_now - v_physical_deduct, GREATEST(0, v_reserved_now - v_quantity),
        p_actor_id
    );
END;
$$;

-- ===========================================================================
-- 7. AUTOMATED VARIANT PRICE EDIT AUDIT TRIGGER
-- Auto-inserts audit_logs records whenever a variant price is modified.
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_audit_variant_price_change()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.price <> NEW.price OR (OLD.compare_at_price IS DISTINCT FROM NEW.compare_at_price) THEN
        INSERT INTO audit_logs (
            entity_type,
            entity_id,
            action,
            actor_id,
            actor_type,
            old_values,
            new_values,
            created_at
        ) VALUES (
            'product_variants',
            NEW.id,
            'UPDATE',
            NULL,
            'system',
            jsonb_build_object(
                'price', OLD.price,
                'compare_at_price', OLD.compare_at_price,
                'sku', OLD.sku
            ),
            jsonb_build_object(
                'price', NEW.price,
                'compare_at_price', NEW.compare_at_price,
                'sku', NEW.sku
            ),
            now()
        );
    END IF;
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_audit_variant_price_change() IS
    'Automatically logs variant price modifications to audit_logs.';

DROP TRIGGER IF EXISTS trg_audit_variant_price ON product_variants;
CREATE TRIGGER trg_audit_variant_price
    AFTER UPDATE OF price, compare_at_price ON product_variants
    FOR EACH ROW EXECUTE FUNCTION fn_audit_variant_price_change();

-- ===========================================================================
-- 8. PAYMENT STATUS TRANSITION GUARD
-- Mirrors fn_validate_order_status_transition(). Payment status is as
-- safety-critical as order status; deferring entirely to the application
-- creates the same gap the order guard was added to close.
-- ERRCODE P0005 reserved for payment transition violations (P0001 = orders).
--
-- *** SIGN-OFF REQUIRED — failed state:
-- A failed intent may return to processing because payments is one-per-order and
-- payment_attempts records each retry. Idempotency remains anchored on payment_id.
-- ===========================================================================
CREATE OR REPLACE FUNCTION fn_validate_payment_status_transition()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    -- Allowed transitions per source status (payment_status_enum adjacency map).
    -- Key  = current (OLD) status.
    -- Value = array of valid next statuses.
    --
    -- Design decisions:
    --   pending → processing: payment gateway call submitted
    --   pending → cancelled: order cancelled before any gateway attempt
    --   pending → failed: immediate failure (gateway unreachable, validation error)
    --   processing → authorized: gateway placed a hold (pre-auth flow)
    --   processing → paid: direct settlement (no pre-auth step)
    --   processing → partially_paid: first installment settled
    --   processing → failed: gateway declined after attempt
    --   processing → cancelled: gateway voided before any settlement
    --   authorized → paid: capture after pre-auth hold
    --   authorized → cancelled: void the hold before capture
    --   paid → refund_pending: full refund initiated
    --   partially_paid → paid: remaining installments fully settled
    --   partially_paid → refund_pending: partial payment refund initiated
    --   partially_paid → failed: remaining installments failed
    --   refund_pending → partially_refunded: first partial refund settled
    --   refund_pending → refunded: full refund settled in one step
    --   partially_refunded → refunded: remaining refund amount settled
    --
    -- failed → processing starts a new payment_attempts row for the same intent.
    allowed JSONB := '{
        "pending":            ["processing", "cancelled", "failed"],
        "processing":         ["authorized", "paid", "partially_paid", "failed", "cancelled"],
        "authorized":         ["paid", "cancelled"],
        "paid":               ["refund_pending"],
        "partially_paid":     ["paid", "refund_pending", "failed"],
        "failed":             ["processing", "cancelled"],
        "cancelled":          [],
        "refund_pending":     ["partially_refunded", "refunded"],
        "partially_refunded": ["refunded"],
        "refunded":           []
    }';
    valid_next TEXT[];
BEGIN
    -- No-op: status unchanged (UPDATE of other columns on this payments row).
    IF OLD.status = NEW.status THEN
        RETURN NEW;
    END IF;

    SELECT ARRAY(
        SELECT jsonb_array_elements_text(allowed -> OLD.status::TEXT)
    ) INTO valid_next;

    IF NOT (NEW.status::TEXT = ANY(valid_next)) THEN
        RAISE EXCEPTION
            'Invalid payment status transition: % → %. Allowed transitions from %: [%]',
            OLD.status, NEW.status, OLD.status, array_to_string(valid_next, ', ')
            USING ERRCODE = 'P0005';
    END IF;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_validate_payment_status_transition() IS
    'BEFORE UPDATE trigger on payments. Enforces payment_status_enum state machine. '
    'Raises P0005 on invalid transition. '
    'The adjacency JSONB is the single source of truth — update it here when '
    'adding new payment gateway flows. failed may transition to processing for '
    'a new payment_attempts row, or to cancelled when the order is abandoned.';

DROP TRIGGER IF EXISTS trg_payments_status_transition ON payments;
CREATE TRIGGER trg_payments_status_transition
    BEFORE UPDATE OF status ON payments
    FOR EACH ROW EXECUTE FUNCTION fn_validate_payment_status_transition();

COMMIT;
