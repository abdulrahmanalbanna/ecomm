-- =============================================================================
-- Migration 001 : PostgreSQL Extensions & Custom Enum Types
-- Project       : commerce-single-vendor
-- Description   : Install required extensions and define domain-specific enum types.
--                 Must be run first; all subsequent migrations depend on these types.
-- Requires      : PostgreSQL 14+
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- EXTENSIONS
-- ---------------------------------------------------------------------------

-- citext: Case-insensitive text type.
--   Used on: users.email, coupons.code
--   Why: Prevents case-mismatch bugs (e.g. User@Example.com vs user@example.com)
--        without sprinkling lower() across every auth query.
CREATE EXTENSION IF NOT EXISTS citext;

-- ltree: Hierarchical label-tree data type.
--   Used on: categories.path
--   Why: Enables single-query subtree traversal (path <@ 'Electronics'),
--        breadcrumb generation, and ancestor lookups without recursive CTEs.
CREATE EXTENSION IF NOT EXISTS ltree;

-- pgcrypto: Cryptographic functions.
--   Used on: gen_random_uuid() for public_id columns.
--   Why: Provides UUID generation without the uuid-ossp extension.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- pg_stat_statements is operational monitoring, not a schema dependency.
-- Install it separately after adding it to shared_preload_libraries and restarting:
--   CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
-- Keeping it outside this transactional dependency migration prevents an optional
-- monitoring prerequisite from rolling back the required types and extensions.

-- ---------------------------------------------------------------------------
-- ENUM TYPES
-- ---------------------------------------------------------------------------

-- Order lifecycle state machine.
-- Design note: Every status transition MUST be recorded in order_events.
-- WARNING: Adding values to a PostgreSQL ENUM is non-transactional (ALTER TYPE ... ADD VALUE
--          cannot run inside a transaction block). Plan enum changes carefully.
CREATE TYPE order_status_enum AS ENUM (
    'pending',           -- Order created, awaiting payment initiation by customer
    'payment_pending',   -- Payment initiated; gateway response not yet received
    'confirmed',         -- Payment confirmed by gateway webhook; order accepted
    'processing',        -- Warehouse is picking and packing the order
    'shipped',           -- Dispatched to carrier; tracking number assigned
    'delivered',         -- Delivery confirmed by carrier or customer acknowledgement
    'cancelled',         -- Cancelled before shipment; triggers inventory reservation release
    'refund_requested',  -- Customer has filed a return or refund request post-delivery
    'refund_rejected',   -- Refund request explicitly denied; TERMINAL — dispute handling is application-level
    'refunded',          -- Refund fully processed and settled by gateway
    'failed'             -- Payment failed after all retry attempts; order is void
);

COMMENT ON TYPE order_status_enum IS
    'Order lifecycle state machine. Valid transitions documented in architecture §4. '
    'Every transition is recorded in order_events. '
    'WARNING: ALTER TYPE ... ADD VALUE cannot run in a transaction block.';

-- Payment intent lifecycle.
-- Separates the payment concern from the order concern (they evolve independently).
CREATE TYPE payment_status_enum AS ENUM (
    'pending',             -- Payment intent created; no charge attempted yet
    'processing',          -- Charge request submitted to Tabby/Tamara; awaiting response
    'authorized',          -- Pre-authorized but not yet captured (gateway hold)
    'paid',                -- Fully settled (full payment method)
    'partially_paid',      -- First installments settled; remaining due (installment method)
    'failed',              -- All charge attempts exhausted; no successful settlement
    'cancelled',           -- Payment voided before any capture (e.g. order cancelled)
    'refund_pending',      -- Refund initiated; awaiting gateway settlement
    'partially_refunded',  -- Partial refund completed; remainder still settled
    'refunded'             -- Fully refunded; no outstanding balance
);

COMMENT ON TYPE payment_status_enum IS
    'Payment intent lifecycle. Separate from order_status_enum — a payment can be '
    'refunded while the order remains in delivered state. '
    'Gateways: Tabby (4-installment) and Tamara (3 or 6 installments).';

COMMIT;
