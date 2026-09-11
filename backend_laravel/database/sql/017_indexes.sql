-- =============================================================================
-- Migration 017 : Supplemental Index Strategy
-- Project       : commerce-single-vendor
-- Depends on    : All prior migrations (all tables must exist)
-- Purpose       : Cross-domain composite, covering, and partial indexes
--                 that are most effective after all tables are in place.
--
-- INDEX PHILOSOPHY (from architecture §7):
--   - Every index here has a specific, documented query it serves.
--   - No index is created "just in case" or because the column exists.
--   - Partial indexes reduce index size and maintenance overhead significantly.
--   - Covering (INCLUDE) indexes enable index-only scans on hot query paths.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- USERS — additional query patterns
-- ---------------------------------------------------------------------------

-- Admin user management: filter by role + active status + recency
-- Query: SELECT * FROM users WHERE role_id = 1 AND is_active = TRUE ORDER BY created_at DESC
CREATE INDEX idx_users_role_active_recent
    ON users (role_id, created_at DESC)
    WHERE is_active = TRUE AND deleted_at IS NULL;

-- ---------------------------------------------------------------------------
-- PRODUCTS — additional query patterns
-- ---------------------------------------------------------------------------

-- Admin product listing: all products in a category regardless of status
-- Query: SELECT * FROM products WHERE category_id = $1 ORDER BY created_at DESC
CREATE INDEX idx_products_category_admin
    ON products (category_id, created_at DESC)
    WHERE deleted_at IS NULL;

-- Price range filter on published products
-- Query: SELECT * FROM product_variants WHERE price BETWEEN $min AND $max AND is_active = TRUE
CREATE INDEX idx_variants_price_range
    ON product_variants (price, product_id)
    WHERE is_active = TRUE;

-- SKU lookup for barcode scanner / warehouse lookup (already UNIQUE — this is a note only)
-- UNIQUE index on product_variants.sku already covers: WHERE sku = $1

-- ---------------------------------------------------------------------------
-- INVENTORY — cross-join with product_variants for catalog display
-- ---------------------------------------------------------------------------

-- In-stock check during category page render (join: product_variants + inventory)
-- Query: SELECT pv.id, i.quantity_available FROM product_variants pv
--          JOIN inventory i ON i.variant_id = pv.id
--         WHERE pv.product_id = $1 AND i.quantity_available > 0
-- Covered by: idx_inventory_in_stock + idx_variants_active_by_product (already created)

-- Covering index for inventory dashboard (avoids heap fetch on reporting queries)
CREATE INDEX idx_inventory_dashboard_cover
    ON inventory (variant_id)
    INCLUDE (quantity_on_hand, quantity_reserved, quantity_available, reorder_point, allow_backorder);

-- ---------------------------------------------------------------------------
-- ORDERS — additional reporting and admin patterns
-- ---------------------------------------------------------------------------

-- Revenue reporting: total orders in a date range by status
-- Query: SELECT SUM(total_amount), COUNT(*) FROM orders
--         WHERE placed_at BETWEEN $start AND $end AND status = 'delivered'
-- NOTE: placed_at must be included for partition pruning. status already indexed.
CREATE INDEX idx_orders_revenue_reporting
    ON orders (placed_at DESC, status)
    INCLUDE (total_amount, user_id);

-- Cancelled/failed order analysis
CREATE INDEX idx_orders_cancelled_failed
    ON orders (cancelled_at DESC)
    WHERE status IN ('cancelled', 'failed') AND cancelled_at IS NOT NULL;

-- ---------------------------------------------------------------------------
-- ORDER ITEMS — sales analytics
-- ---------------------------------------------------------------------------

-- Best-selling products report
-- Query: SELECT variant_id, SUM(quantity) FROM order_items GROUP BY variant_id ORDER BY SUM DESC
-- Covered by: idx_order_items_variant (already created)

-- Revenue per variant with date filtering
CREATE INDEX idx_order_items_variant_revenue
    ON order_items (variant_id, created_at DESC)
    INCLUDE (quantity, total_price);

-- ---------------------------------------------------------------------------
-- PAYMENTS — reconciliation and reporting
-- ---------------------------------------------------------------------------

-- Payment reconciliation by gateway and date
-- Query: SELECT * FROM payments WHERE gateway_id = $1 AND status = 'paid'
--          AND created_at BETWEEN $start AND $end
CREATE INDEX idx_payments_reconciliation
    ON payments (gateway_id, status, created_at DESC)
    INCLUDE (amount, order_id);

-- Installment payment lookup: find all active installment payments
CREATE INDEX idx_payments_installment_active
    ON payments (payment_method, status)
    WHERE payment_method = 'installment' AND status IN ('partially_paid', 'paid');

-- ---------------------------------------------------------------------------
-- INSTALLMENTS — scheduled job patterns
-- ---------------------------------------------------------------------------

-- Overdue installments alert (scheduled job: run nightly).
--
-- WHY no date in the predicate:
--   CURRENT_DATE is STABLE (re-evaluates per query), not IMMUTABLE.
--   PostgreSQL requires index predicates to be strictly IMMUTABLE because the
--   index is a static on-disk structure — a STABLE predicate would silently
--   mis-classify rows already stored in the index as new dates arrive.
--
-- FIX: predicate uses only the IMMUTABLE literal status values.
--   The date range is applied at query time, not in the index definition.
--   The B-Tree on (due_date ASC) still makes the date scan efficient:
--
--   SELECT * FROM installments
--    WHERE status IN ('pending', 'failed')
--      AND due_date < CURRENT_DATE          -- applied at query time ✓
--    ORDER BY due_date ASC;
CREATE INDEX idx_installments_overdue
    ON installments (due_date ASC, plan_id)
    WHERE status IN ('pending', 'failed');   -- IMMUTABLE literals only ✓

-- ---------------------------------------------------------------------------
-- PAYMENT ATTEMPTS — failure analysis
-- ---------------------------------------------------------------------------

-- Failed attempt analysis per gateway (fraud / gateway health monitoring)
CREATE INDEX idx_attempts_failures
    ON payment_attempts (attempted_at DESC, failure_code)
    WHERE status = 'failed' AND failure_code IS NOT NULL;

-- ---------------------------------------------------------------------------
-- COUPONS — checkout validation path
-- ---------------------------------------------------------------------------

-- Full coupon validation at checkout:
-- Query: SELECT * FROM coupons
--         WHERE code = $1 AND is_active = TRUE
--           AND (valid_from IS NULL OR valid_from <= now())
--           AND (valid_until IS NULL OR valid_until > now())
--           AND (max_uses IS NULL OR used_count < max_uses)
-- Covered by: UNIQUE on code (CITEXT) + idx_coupons_active_code (already created)

-- ---------------------------------------------------------------------------
-- PRODUCT REVIEWS — aggregate statistics
-- ---------------------------------------------------------------------------

-- Average rating per product (materialized in mv_product_performance, but useful here too)
-- Query: SELECT product_id, AVG(rating), COUNT(*) FROM product_reviews
--         WHERE is_approved = TRUE GROUP BY product_id
CREATE INDEX idx_reviews_rating_stats
    ON product_reviews (product_id, rating)
    WHERE is_approved = TRUE;

-- ---------------------------------------------------------------------------
-- NOTIFICATIONS — dispatch queue optimisation
-- ---------------------------------------------------------------------------

-- Dispatch queue filtered by channel (send email batch, then SMS batch)
CREATE INDEX idx_notifications_channel_queue
    ON notifications (channel, created_at ASC)
    WHERE sent_at IS NULL;

-- ---------------------------------------------------------------------------
-- SESSIONS — cleanup job
-- ---------------------------------------------------------------------------

-- Expired session cleanup: DELETE FROM sessions WHERE expires_at < now()
CREATE INDEX idx_sessions_expiry
    ON sessions (expires_at ASC)
    WHERE revoked_at IS NULL;

-- ---------------------------------------------------------------------------
-- AUDIT LOGS — compliance reporting
-- ---------------------------------------------------------------------------

-- Status change events per entity (e.g. all order status changes)
-- Query: SELECT * FROM audit_logs
--         WHERE entity_type = 'orders' AND action = 'STATUS_CHANGE'
--           AND created_at BETWEEN $start AND $end
CREATE INDEX idx_audit_status_changes
    ON audit_logs (entity_type, action, created_at DESC)
    WHERE action = 'STATUS_CHANGE';

COMMIT;
