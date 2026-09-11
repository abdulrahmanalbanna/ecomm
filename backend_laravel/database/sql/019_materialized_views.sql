-- =============================================================================
-- Migration 019 : Materialized Views (Reporting Layer)
-- Project       : commerce-single-vendor
-- Depends on    : All prior migrations
-- Contents      :
--   1. mv_daily_sales          — Daily revenue + order KPIs
--   2. mv_monthly_revenue      — Monthly financial summary
--   3. mv_product_performance  — Per-product sales, revenue, rating
--   4. mv_inventory_status     — Real-time-ish inventory health snapshot
--   5. mv_category_performance — Category-level sales aggregation
--
-- REFRESH STRATEGY:
--   Each view documents its recommended refresh frequency and whether
--   CONCURRENTLY is appropriate. CONCURRENTLY requires a UNIQUE index on the MV
--   and takes two table scans (slower but non-blocking for reads).
--
-- SCHEDULING: Use pg_cron (CREATE EXTENSION pg_cron) for automated refresh.
--   Example: SELECT cron.schedule('refresh-daily-sales', '5 1 * * *',
--              'REFRESH MATERIALIZED VIEW CONCURRENTLY mv_daily_sales');
-- =============================================================================

BEGIN;

-- ===========================================================================
-- 1. mv_daily_sales
-- Pre-aggregated daily sales KPIs for the admin dashboard chart.
-- Refresh: DAILY at 01:05 AM (after midnight UTC) via pg_cron.
-- CONCURRENTLY: YES — UNIQUE index on sale_date makes it safe and non-blocking.
-- Data lag: up to 24 hours. Show "as of <last_refreshed>" in the UI.
-- ===========================================================================
CREATE MATERIALIZED VIEW mv_daily_sales AS
SELECT
    DATE_TRUNC('day', o.placed_at)::DATE AS sale_date,
    COUNT(*) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )) AS order_count,
    COUNT(DISTINCT o.user_id) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )) AS unique_customers,
    COALESCE(SUM(o.total_amount) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )), 0) AS gross_revenue,
    COALESCE(SUM(o.discount_amount) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )), 0) AS total_discounts,
    COALESCE(SUM(o.shipping_amount) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )), 0) AS total_shipping,
    COALESCE(SUM(o.tax_amount) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )), 0) AS total_tax,
    COALESCE(SUM(o.total_amount) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )), 0) AS net_revenue,
    ROUND(AVG(o.total_amount) FILTER (WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )), 2) AS avg_order_value,
    COALESCE(SUM(o.total_amount) FILTER (WHERE o.status = 'delivered'), 0) AS confirmed_revenue,
    COUNT(*) FILTER (WHERE o.status = 'cancelled') AS cancelled_count,
    COUNT(*) FILTER (WHERE o.status = 'refunded')  AS refunded_count
FROM orders o
GROUP BY DATE_TRUNC('day', o.placed_at)::DATE
ORDER BY sale_date DESC
WITH NO DATA;

-- UNIQUE index enables REFRESH CONCURRENTLY (non-blocking reads during refresh)
CREATE UNIQUE INDEX mv_daily_sales_pk
    ON mv_daily_sales (sale_date);

CREATE INDEX mv_daily_sales_revenue
    ON mv_daily_sales (gross_revenue DESC);

COMMENT ON MATERIALIZED VIEW mv_daily_sales IS
    'Daily sales KPIs. Refresh: daily at 01:05 AM. UNIQUE(sale_date) enables CONCURRENTLY. '
    'Cmd: REFRESH MATERIALIZED VIEW CONCURRENTLY mv_daily_sales;';

-- Initial populate
REFRESH MATERIALIZED VIEW mv_daily_sales;

-- ===========================================================================
-- 2. mv_monthly_revenue
-- Monthly financial summary for P&L reports and finance team exports.
-- Refresh: MONTHLY on the 1st at 02:00 AM.
-- CONCURRENTLY: YES.
-- ===========================================================================
CREATE MATERIALIZED VIEW mv_monthly_revenue AS
WITH refund_totals AS (
    SELECT payment_id, SUM(amount) AS refunded_amount
    FROM refunds
    WHERE status = 'processed'
    GROUP BY payment_id
), recognized_orders AS (
    SELECT o.*, p.payment_method, COALESCE(rt.refunded_amount, 0) AS refunded_amount
    FROM orders o
    LEFT JOIN payments p ON p.order_id = o.id
    LEFT JOIN refund_totals rt ON rt.payment_id = p.id
    WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )
)
SELECT
    DATE_TRUNC('month', placed_at)::DATE AS month,
    COUNT(*) AS total_orders,
    COUNT(DISTINCT user_id) AS unique_customers,
    SUM(total_amount) AS gross_revenue,
    SUM(discount_amount) AS total_discounts,
    SUM(shipping_amount) AS total_shipping,
    SUM(tax_amount) AS total_tax,
    SUM(total_amount) AS net_revenue,
    ROUND(AVG(total_amount), 2) AS avg_order_value,
    COUNT(*) FILTER (WHERE payment_method = 'full') AS full_payment_orders,
    COUNT(*) FILTER (WHERE payment_method = 'installment') AS installment_orders,
    COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'full'), 0) AS full_payment_revenue,
    COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'installment'), 0) AS installment_revenue,
    SUM(refunded_amount) AS total_refunded,
    SUM(total_amount - refunded_amount) AS net_settled_revenue
FROM recognized_orders
GROUP BY DATE_TRUNC('month', placed_at)::DATE
ORDER BY month DESC
WITH NO DATA;

CREATE UNIQUE INDEX mv_monthly_revenue_pk
    ON mv_monthly_revenue (month);

COMMENT ON MATERIALIZED VIEW mv_monthly_revenue IS
    'Monthly P&L summary including payment method split and refund impact. '
    'Refresh: monthly on the 1st at 02:00 AM. CONCURRENTLY safe. '
    'Cmd: REFRESH MATERIALIZED VIEW CONCURRENTLY mv_monthly_revenue;';

REFRESH MATERIALIZED VIEW mv_monthly_revenue;

-- ===========================================================================
-- 3. mv_product_performance
-- Per-product sales metrics and ratings for merchandising decisions.
-- Refresh: DAILY at 02:30 AM.
-- CONCURRENTLY: YES.
-- Uses window functions (RANK) to avoid application-side sorting.
-- ===========================================================================
CREATE MATERIALIZED VIEW mv_product_performance AS
WITH product_sales AS (
    SELECT
        pv.product_id,
        SUM(oi.quantity)      AS total_units_sold,
        SUM(oi.total_price)   AS total_revenue,
        COUNT(DISTINCT oi.order_id) AS total_orders
    FROM order_items oi
    JOIN product_variants pv ON pv.id = oi.variant_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )
    GROUP BY pv.product_id
),
product_ratings AS (
    SELECT
        product_id,
        ROUND(AVG(rating), 2) AS avg_rating,
        COUNT(*)              AS review_count
    FROM product_reviews
    WHERE is_approved = TRUE
    GROUP BY product_id
)
SELECT
    p.id                                                       AS product_id,
    p.name                                                     AS product_name,
    p.slug                                                     AS product_slug,
    c.id                                                       AS category_id,
    c.name                                                     AS category_name,
    COALESCE(ps.total_units_sold, 0)                           AS total_units_sold,
    COALESCE(ps.total_revenue, 0)                              AS total_revenue,
    COALESCE(ps.total_orders, 0)                               AS total_orders,
    COALESCE(pr.avg_rating, 0)                                 AS avg_rating,
    COALESCE(pr.review_count, 0)                               AS review_count,
    -- Rank within category by revenue (for "top products in category" reports)
    RANK() OVER (
        PARTITION BY p.category_id
        ORDER BY COALESCE(ps.total_revenue, 0) DESC
    )                                                          AS revenue_rank_in_category,
    -- Global revenue rank across all products
    RANK() OVER (
        ORDER BY COALESCE(ps.total_revenue, 0) DESC
    )                                                          AS global_revenue_rank,
    p.is_active,
    p.status                                                   AS product_status,
    p.created_at                                               AS product_created_at
FROM products p
JOIN categories c ON c.id = p.category_id
LEFT JOIN product_sales ps ON ps.product_id = p.id
LEFT JOIN product_ratings pr ON pr.product_id = p.id
WHERE p.deleted_at IS NULL
WITH NO DATA;

CREATE UNIQUE INDEX mv_product_performance_pk
    ON mv_product_performance (product_id);

CREATE INDEX mv_product_performance_revenue
    ON mv_product_performance (total_revenue DESC);

CREATE INDEX mv_product_performance_category
    ON mv_product_performance (category_id, revenue_rank_in_category);

COMMENT ON MATERIALIZED VIEW mv_product_performance IS
    'Per-product sales, revenue, and rating metrics with category and global revenue rank. '
    'Refresh: daily at 02:30 AM. Uses window functions (RANK) for in-SQL ranking. '
    'Cmd: REFRESH MATERIALIZED VIEW CONCURRENTLY mv_product_performance;';

REFRESH MATERIALIZED VIEW mv_product_performance;

-- ===========================================================================
-- 4. mv_inventory_status
-- Near-real-time inventory health snapshot for admin warehouse dashboard.
-- Refresh: EVERY 15 MINUTES via pg_cron (frequent refresh acceptable — view is lightweight).
-- CONCURRENTLY: YES.
-- ===========================================================================
CREATE MATERIALIZED VIEW mv_inventory_status AS
SELECT
    p.id                                AS product_id,
    p.name                              AS product_name,
    p.slug                              AS product_slug,
    p.category_id,
    pv.id                               AS variant_id,
    pv.sku,
    pv.name                             AS variant_name,
    pv.price,
    i.quantity_on_hand,
    i.quantity_reserved,
    i.quantity_available,
    i.reorder_point,
    i.allow_backorder,
    -- Stock status label for easy dashboard display
    CASE
        WHEN i.quantity_available <= 0 AND NOT i.allow_backorder THEN 'out_of_stock'
        WHEN i.quantity_available <= 0 AND i.allow_backorder     THEN 'backorder'
        WHEN i.quantity_available <= i.reorder_point             THEN 'low_stock'
        ELSE                                                          'in_stock'
    END                                 AS stock_status,
    -- Estimated days until stockout (based on recent 30-day velocity)
    CASE
        WHEN COALESCE(recent.units_sold_30d, 0) = 0 THEN NULL
        ELSE ROUND(i.quantity_available::NUMERIC / (recent.units_sold_30d / 30.0))
    END                                 AS days_of_stock,
    COALESCE(recent.units_sold_30d, 0)  AS units_sold_last_30_days,
    i.updated_at                        AS inventory_last_updated
FROM inventory i
JOIN product_variants pv ON pv.id = i.variant_id
JOIN products p ON p.id = pv.product_id
LEFT JOIN (
    -- Recent 30-day sales velocity per variant
    SELECT
        oi.variant_id,
        SUM(oi.quantity) AS units_sold_30d
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.placed_at >= now() - INTERVAL '30 days'
      AND o.status IN (
          'confirmed', 'processing', 'shipped', 'delivered',
          'refund_requested', 'refund_rejected', 'refunded'
      )
    GROUP BY oi.variant_id
) recent ON recent.variant_id = pv.id
WHERE pv.is_active = TRUE
  AND p.deleted_at IS NULL
WITH NO DATA;

CREATE UNIQUE INDEX mv_inventory_status_pk
    ON mv_inventory_status (variant_id);

CREATE INDEX mv_inventory_status_stock
    ON mv_inventory_status (stock_status, quantity_available);

CREATE INDEX mv_inventory_status_product
    ON mv_inventory_status (product_id);

COMMENT ON MATERIALIZED VIEW mv_inventory_status IS
    'Near-real-time inventory snapshot with stock status labels and 30-day velocity. '
    'Refresh: every 15 minutes. Show "as of <last_refresh>" timestamp in admin UI. '
    'Cmd: REFRESH MATERIALIZED VIEW CONCURRENTLY mv_inventory_status;';

REFRESH MATERIALIZED VIEW mv_inventory_status;

-- ===========================================================================
-- 5. mv_category_performance
-- Category-level revenue aggregation for merchandising and SEO prioritisation.
-- Refresh: DAILY at 03:00 AM.
-- CONCURRENTLY: YES.
-- ===========================================================================
CREATE MATERIALIZED VIEW mv_category_performance AS
WITH product_sales AS (
    SELECT
        pv.product_id,
        SUM(oi.quantity) AS total_units_sold,
        SUM(oi.total_price) AS total_revenue
    FROM order_items oi
    JOIN product_variants pv ON pv.id = oi.variant_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status IN (
        'confirmed', 'processing', 'shipped', 'delivered',
        'refund_requested', 'refund_rejected', 'refunded'
    )
    GROUP BY pv.product_id
), product_ratings AS (
    SELECT product_id, AVG(rating) AS avg_rating, COUNT(*) AS rating_count
    FROM product_reviews
    WHERE is_approved = TRUE
    GROUP BY product_id
), direct_product_metrics AS (
    SELECT
        p.category_id,
        p.id AS product_id,
        COALESCE(ps.total_units_sold, 0) AS total_units_sold,
        COALESCE(ps.total_revenue, 0) AS total_revenue,
        pr.avg_rating,
        COALESCE(pr.rating_count, 0) AS rating_count
    FROM products p
    LEFT JOIN product_sales ps ON ps.product_id = p.id
    LEFT JOIN product_ratings pr ON pr.product_id = p.id
    WHERE p.deleted_at IS NULL
), category_rollup AS (
    SELECT
        ancestor.id AS category_id,
        COUNT(DISTINCT dpm.product_id) AS product_count,
        SUM(dpm.total_units_sold) AS total_units_sold,
        SUM(dpm.total_revenue) AS total_revenue,
        SUM(dpm.avg_rating * dpm.rating_count) / NULLIF(SUM(dpm.rating_count), 0) AS avg_product_rating
    FROM categories ancestor
    JOIN categories direct_category ON ancestor.path @> direct_category.path
    JOIN direct_product_metrics dpm ON dpm.category_id = direct_category.id
    WHERE ancestor.is_active = TRUE
      AND direct_category.is_active = TRUE
    GROUP BY ancestor.id
), category_orders AS (
    SELECT
        ancestor.id AS category_id,
        COUNT(DISTINCT oi.order_id) AS total_orders
    FROM categories ancestor
    JOIN categories direct_category ON ancestor.path @> direct_category.path
    JOIN products p ON p.category_id = direct_category.id AND p.deleted_at IS NULL
    JOIN product_variants pv ON pv.product_id = p.id
    JOIN order_items oi ON oi.variant_id = pv.id
    JOIN orders o ON o.id = oi.order_id
    WHERE ancestor.is_active = TRUE
      AND direct_category.is_active = TRUE
      AND o.status IN (
          'confirmed', 'processing', 'shipped', 'delivered',
          'refund_requested', 'refund_rejected', 'refunded'
      )
    GROUP BY ancestor.id
)
SELECT
    c.id AS category_id,
    c.name AS category_name,
    c.slug AS category_slug,
    c.depth AS category_depth,
    c.parent_id,
    COALESCE(cr.product_count, 0) AS product_count,
    COALESCE(co.total_orders, 0) AS total_orders,
    COALESCE(cr.total_units_sold, 0) AS total_units_sold,
    COALESCE(cr.total_revenue, 0) AS total_revenue,
    COALESCE(ROUND(cr.avg_product_rating, 2), 0) AS avg_product_rating,
    RANK() OVER (ORDER BY COALESCE(cr.total_revenue, 0) DESC) AS revenue_rank
FROM categories c
LEFT JOIN category_rollup cr ON cr.category_id = c.id
LEFT JOIN category_orders co ON co.category_id = c.id
WHERE c.is_active = TRUE
WITH NO DATA;

CREATE UNIQUE INDEX mv_category_performance_pk
    ON mv_category_performance (category_id);

CREATE INDEX mv_category_performance_revenue
    ON mv_category_performance (total_revenue DESC);

COMMENT ON MATERIALIZED VIEW mv_category_performance IS
    'Category-level sales aggregation. Used for merchandising and category SEO prioritisation. '
    'Refresh: daily at 03:00 AM. '
    'Cmd: REFRESH MATERIALIZED VIEW CONCURRENTLY mv_category_performance;';

REFRESH MATERIALIZED VIEW mv_category_performance;

COMMIT;
