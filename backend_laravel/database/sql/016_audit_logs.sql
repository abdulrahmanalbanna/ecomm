-- =============================================================================
-- Migration 016 : Audit Logs
-- Project       : commerce-single-vendor
-- Tables        : audit_logs (RANGE-partitioned monthly)
-- Depends on    : 002_identity_auth.sql (users)
-- Design notes  :
--   - Highest write-volume table in the system. RANGE-partitioned by created_at (monthly).
--   - Append-only: rows are NEVER updated or deleted during hot retention period.
--   - BRIN index is ideal here: tiny overhead, covers time-range scans naturally.
--   - B-Tree on (entity_type, entity_id): "show me all events for order #1234".
--   - GIN on new_values JSONB: compliance queries ("find all price changes to product X").
--   - Retention strategy:
--       * Hot:  12 months in primary DB (partitioned, indexed, fast)
--       * Warm: 12–36 months in archive DB (separate PostgreSQL instance via FDW)
--       * Cold: > 36 months in object storage (S3/GCS via pg_dump or COPY TO CSV)
--   - No FK from audit_logs to other tables (audit must survive entity deletion).
-- =============================================================================

BEGIN;

CREATE SEQUENCE audit_logs_id_seq
    AS BIGINT
    START 1
    INCREMENT 1
    CACHE 100;

CREATE TABLE audit_logs (
    id          BIGINT       NOT NULL DEFAULT nextval('audit_logs_id_seq'),
    -- Entity type: the table name being audited.
    -- Examples: 'orders', 'products', 'users', 'payments', 'inventory'
    entity_type VARCHAR(100) NOT NULL,
    -- Primary key value of the audited row.
    -- BIGINT covers BIGSERIAL PKs. For UUID PKs, store as TEXT and cast.
    entity_id   BIGINT       NOT NULL,
    -- The operation performed.
    action      VARCHAR(50)  NOT NULL
                CHECK (action IN ('INSERT', 'UPDATE', 'DELETE', 'STATUS_CHANGE', 'LOGIN', 'LOGOUT', 'EXPORT', 'ADMIN_ACTION')),
    -- The user who performed the action. NULL if triggered by system/cron.
    actor_id    BIGINT,                          -- No FK: actor user may be deleted later
    actor_type  VARCHAR(20)  NOT NULL DEFAULT 'system'
                CHECK (actor_type IN ('admin', 'customer', 'system', 'webhook')),
    -- JSONB snapshots of the row before and after the change.
    -- Both NULL for INSERT (no old values) / DELETE (no new values).
    old_values  JSONB,
    new_values  JSONB,
    -- Client IP for security audit. INET handles both IPv4 and IPv6.
    ip_address  INET,
    -- Partition key — must be NOT NULL and part of the PK.
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    -- Composite PK required by PostgreSQL RANGE partitioning rules.
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

COMMENT ON TABLE  audit_logs            IS 'Immutable append-only audit log. Partitioned monthly by created_at. Never UPDATE or DELETE rows during retention period.';
COMMENT ON COLUMN audit_logs.entity_type IS 'Name of the audited table: orders, products, users, payments, inventory, etc.';
COMMENT ON COLUMN audit_logs.entity_id  IS 'Internal BIGINT primary key of the audited row (matches the BIGSERIAL PK '
    'of the source table). All tables in this schema use BIGSERIAL internal PKs; '
    'public_id (UUID) is a separate column and is never stored here. '
    'No FK constraint: audit rows must survive entity deletion.';
COMMENT ON COLUMN audit_logs.actor_id   IS 'User who triggered the action. No FK (actor may be soft-deleted later).';
COMMENT ON COLUMN audit_logs.old_values IS 'JSONB snapshot of row before change. NULL for INSERT events.';
COMMENT ON COLUMN audit_logs.new_values IS 'JSONB snapshot of row after change. NULL for DELETE events. GIN-indexed for compliance queries.';
COMMENT ON COLUMN audit_logs.created_at IS 'Partition key. Include in WHERE clauses for partition pruning.';

-- Monthly partitions 2025–2028.
-- Recommendation: deploy pg_partman before 2028-10-01 for automated ongoing partition management.
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
            tname   := format('audit_logs_%s_%s', y, lpad(m::TEXT, 2, '0'));
            EXECUTE format(
                'CREATE TABLE IF NOT EXISTS %I
                 PARTITION OF audit_logs
                 FOR VALUES FROM (%L) TO (%L)',
                tname, p_start, p_end
            );
        END LOOP;
    END LOOP;
END;
$$;

CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT;

-- BRIN index: optimal for append-only time-series. Stores min/max per block range.
-- Extremely low storage overhead. Covers time-range scans (created_at BETWEEN ... AND ...).
CREATE INDEX idx_audit_brin_created
    ON audit_logs USING BRIN (created_at);

-- B-Tree for entity-specific audit trail: "all events for orders.id = 5"
CREATE INDEX idx_audit_entity
    ON audit_logs (entity_type, entity_id, created_at DESC);

-- B-Tree for actor audit trail: "all actions by admin user X"
CREATE INDEX idx_audit_actor
    ON audit_logs (actor_id, created_at DESC)
    WHERE actor_id IS NOT NULL;

-- GIN index on new_values JSONB for compliance queries:
-- e.g. "find all orders where total_amount changed above 10000"
-- SELECT * FROM audit_logs WHERE new_values @> '{"status": "refunded"}' AND entity_type = 'orders'
CREATE INDEX idx_audit_new_values_gin
    ON audit_logs USING GIN (new_values)
    WHERE new_values IS NOT NULL;

CREATE OR REPLACE FUNCTION fn_block_audit_log_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only; % is not permitted', TG_OP
        USING ERRCODE = '55000';
END;
$$;

CREATE TRIGGER trg_audit_logs_append_only
    BEFORE UPDATE OR DELETE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION fn_block_audit_log_mutation();

-- ---------------------------------------------------------------------------
-- DEFAULT PARTITION MONITORING (documented snippet — no DDL)
-- Monthly partitions exist through 2028-12-31 on orders, order_events,
-- inventory_movements, and audit_logs. Rows beyond that date fall into the
-- DEFAULT partition. Ops should alert on non-zero row counts in DEFAULT
-- partitions — it signals that partition creation has fallen behind.
-- Wire into your monitoring/alerting system. Long-term: deploy pg_partman.
--
-- MONITORING QUERY — run periodically (e.g. via pg_cron, Datadog, CloudWatch).
-- Alert on n_live_tup > 0 for any of these four DEFAULT partitions.
--
-- SELECT
--     relname          AS partition_name,
--     n_live_tup       AS estimated_live_rows,
--     last_analyze,
--     last_autoanalyze
-- FROM pg_stat_user_tables
-- WHERE relname IN (
--     'orders_default',
--     'order_events_default',
--     'inventory_movements_default',
--     'audit_logs_default'
-- )
-- ORDER BY n_live_tup DESC;
--
-- Broader variant — catches any *_default partition by naming convention:
-- SELECT relname, n_live_tup
-- FROM pg_stat_user_tables
-- WHERE relname LIKE '%_default'
--   AND n_live_tup > 0
-- ORDER BY n_live_tup DESC;
--
-- ACTION: when triggered, create the next year's monthly partitions and ANALYZE
-- the parent table.
-- ---------------------------------------------------------------------------

COMMIT;
