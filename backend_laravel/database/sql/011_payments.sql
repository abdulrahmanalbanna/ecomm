-- =============================================================================
-- Migration 011 : Payments, Payment Attempts, Transactions & Webhook Events
-- Project       : commerce-single-vendor
-- Tables        : payments, payment_attempts, payment_transactions,
--                 payment_webhook_events
-- Depends on    : 008_orders.sql (orders)
--                 010_payment_gateways.sql (payment_gateways)
--                 001_extensions_enums.sql (payment_status_enum)
-- Design notes  :
--   - Four concepts are explicitly separated: Payment intent, Payment attempt,
--     Settled transaction, and Gateway webhook event.
--   - IDEMPOTENCY:
--       * payments.idempotency_key — client-supplied, prevents duplicate charges on retry
--       * payment_transactions.gateway_transaction_id — UNIQUE prevents double-booking
--       * payment_webhook_events.gateway_event_id — UNIQUE for webhook deduplication
--         (INSERT ... ON CONFLICT DO NOTHING in application webhook handler)
--   - PCI-DSS: NEVER store card numbers, CVV, or raw card data in any column.
--   - payments FK to orders references orders(id).
-- =============================================================================

BEGIN;
-- PAYMENTS
-- The canonical payment intent for an order. Exactly one per order (UNIQUE).
CREATE TABLE payments (
    id                 BIGSERIAL           PRIMARY KEY,
    public_id          UUID                UNIQUE NOT NULL DEFAULT gen_random_uuid(),
    order_id           BIGINT              UNIQUE NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    gateway_id         BIGINT              NOT NULL REFERENCES payment_gateways(id) ON DELETE RESTRICT,
    -- 'full' = single charge via Tabby/Tamara full payment
    -- 'installment' = spread via Tabby (4x) or Tamara (3x/6x) installment plan
    payment_method     VARCHAR(20)         NOT NULL CHECK (payment_method IN ('full', 'installment')),
    amount             NUMERIC(12,2)       NOT NULL CHECK (amount > 0),
    currency           CHAR(3)             NOT NULL DEFAULT 'SAR',
    status             payment_status_enum NOT NULL DEFAULT 'pending',
    idempotency_key    VARCHAR(200)        UNIQUE NOT NULL,
    gateway_payment_id VARCHAR(200),
    gateway_response   JSONB,
    created_at         TIMESTAMPTZ         NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ         NOT NULL DEFAULT now(),
    CONSTRAINT chk_payments_gateway_id_on_paid
        CHECK (
            status NOT IN ('paid', 'authorized', 'partially_paid', 'refund_pending',
                           'partially_refunded', 'refunded')
            OR gateway_payment_id IS NOT NULL
        )
);

COMMENT ON TABLE  payments                   IS 'Payment intent — one per order (enforced by UNIQUE constraint). Separates business intent from execution attempts.';
COMMENT ON COLUMN payments.idempotency_key   IS 'Client-supplied UUID. UNIQUE prevents duplicate charges on client retry.';
COMMENT ON COLUMN payments.gateway_payment_id IS 'Gateway''s session/payment reference returned at checkout initiation.';
COMMENT ON COLUMN payments.gateway_response  IS 'JSONB: raw gateway response snapshot. Flexible across Tabby and Tamara API schemas.';

CREATE INDEX idx_payments_order
    ON payments (order_id);

CREATE INDEX idx_payments_status
    ON payments (status);

CREATE INDEX idx_payments_gateway
    ON payments (gateway_id, status);

-- ---------------------------------------------------------------------------
-- PAYMENT ATTEMPTS
-- Every charge attempt (including retries and failures).
-- Provides a full audit trail of gateway interactions for a payment intent.
-- ---------------------------------------------------------------------------
CREATE TABLE payment_attempts (
    id                    BIGSERIAL   PRIMARY KEY,
    payment_id            BIGINT      NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    -- Increments on each retry: 1, 2, 3, ...
    attempt_number        SMALLINT    NOT NULL DEFAULT 1 CHECK (attempt_number >= 1),
    -- Gateway's transaction reference for THIS attempt (may be NULL until gateway responds).
    gateway_transaction_id VARCHAR(200),
    amount                NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    currency              CHAR(3)     NOT NULL DEFAULT 'SAR',
    status                VARCHAR(30) NOT NULL DEFAULT 'pending'
                                      CHECK (status IN ('pending', 'success', 'failed', 'cancelled', 'expired')),
    -- Machine-readable error code from the gateway (e.g. 'insufficient_funds').
    failure_code          VARCHAR(100),
    -- Human-readable error message for admin review (never expose raw to customers).
    failure_message       TEXT,
    -- Raw gateway response for this specific attempt.
    gateway_response      JSONB,
    attempted_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at          TIMESTAMPTZ,
    CONSTRAINT uq_payment_attempt_number UNIQUE (payment_id, attempt_number),
    CONSTRAINT uq_payment_attempts_payment_id_id UNIQUE (payment_id, id)
);

COMMENT ON TABLE  payment_attempts                    IS 'One row per charge attempt. Captures retries and failures. Full gateway interaction audit trail.';
COMMENT ON COLUMN payment_attempts.attempt_number     IS 'Monotonically increasing per payment. Composite UNIQUE (payment_id, attempt_number).';
COMMENT ON COLUMN payment_attempts.failure_code       IS 'Gateway machine-readable error code. Used for retry logic and analytics.';
COMMENT ON COLUMN payment_attempts.failure_message    IS 'Gateway human-readable error. Log for admin; sanitize before any customer display.';
COMMENT ON COLUMN payment_attempts.gateway_response   IS 'Full raw gateway response for this attempt. JSONB for Tabby/Tamara schema flexibility.';

CREATE INDEX idx_attempts_payment
    ON payment_attempts (payment_id, attempted_at DESC);

-- ---------------------------------------------------------------------------
-- PAYMENT TRANSACTIONS
-- Settled financial movements. Used for reconciliation and reporting.
-- Only created on successful, settled outcomes (charges, refunds, chargebacks).
-- ---------------------------------------------------------------------------
CREATE TABLE payment_transactions (
    id                    BIGSERIAL   PRIMARY KEY,
    payment_id            BIGINT      NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    -- attempt_id references the specific payment_attempts row for this transaction.
    -- NULL is permitted for system-generated adjustments and chargeback records that
    -- are not linked to a specific attempt. For charges, this should always be
    -- populated. The FK is enforced only when the value is non-NULL.
    attempt_id            BIGINT,
    transaction_type      VARCHAR(30) NOT NULL
                                      CHECK (transaction_type IN ('charge', 'refund', 'chargeback', 'adjustment')),
    -- UNIQUE on gateway's transaction ID prevents double-booking a settled transaction.
    -- Use: INSERT ... ON CONFLICT (gateway_transaction_id) DO NOTHING for idempotent recording.
    gateway_transaction_id VARCHAR(200) UNIQUE NOT NULL,
    amount                NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    currency              CHAR(3)     NOT NULL DEFAULT 'SAR',
    -- The moment the gateway confirmed this transaction as settled.
    settled_at            TIMESTAMPTZ,
    -- Extra metadata: gateway fee, tax, split amounts (varies by gateway and transaction type).
    metadata              JSONB       NOT NULL DEFAULT '{}',
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_payment_transactions_payment_id_id UNIQUE (payment_id, id),
    CONSTRAINT fk_payment_transactions_attempt
        FOREIGN KEY (payment_id, attempt_id)
        REFERENCES payment_attempts (payment_id, id)
        ON DELETE RESTRICT
);

COMMENT ON TABLE  payment_transactions                        IS 'Settled financial movements. Used for reconciliation. UNIQUE(gateway_transaction_id) prevents double-booking.';
COMMENT ON COLUMN payment_transactions.gateway_transaction_id IS 'UNIQUE: prevents double-recording. Application: INSERT ... ON CONFLICT (gateway_transaction_id) DO NOTHING.';
COMMENT ON COLUMN payment_transactions.settled_at             IS 'Gateway-reported settlement timestamp. May differ from created_at (async settlement).';

CREATE INDEX idx_transactions_payment
    ON payment_transactions (payment_id, created_at DESC);

CREATE INDEX idx_transactions_type
    ON payment_transactions (transaction_type, settled_at DESC);

-- ---------------------------------------------------------------------------
-- PAYMENT WEBHOOK EVENTS
-- Raw inbound events from Tabby and Tamara webhook endpoints.
-- Stored before processing to enable idempotent handling and replay.
-- ---------------------------------------------------------------------------
CREATE TABLE payment_webhook_events (
    id               BIGSERIAL   PRIMARY KEY,
    gateway_id       BIGINT      NOT NULL REFERENCES payment_gateways(id) ON DELETE RESTRICT,
    -- Gateway-specific event type string, e.g. 'payment.completed', 'installment.paid'.
    event_type       VARCHAR(100) NOT NULL,
    -- UNIQUE: prevents duplicate processing on network retries or gateway re-delivery.
    -- Application webhook handler: INSERT ... ON CONFLICT (gateway_event_id) DO NOTHING.
    gateway_event_id VARCHAR(200) UNIQUE NOT NULL,
    -- Raw webhook request body. Stored as-is for audit trail and replay capability.
    -- PCI-DSS: gateway webhooks must NOT contain card data; only tokenized references.
    payload          JSONB        NOT NULL,
    -- Processing state: FALSE = queued, TRUE = processed.
    processed        BOOLEAN      NOT NULL DEFAULT FALSE,
    processed_at     TIMESTAMPTZ,
    -- If processing failed, store the error for debugging and retry.
    error_message    TEXT,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  payment_webhook_events                  IS 'Raw inbound gateway webhook events. Stored before processing for idempotency and audit. Both Tabby and Tamara deliver here.';
COMMENT ON COLUMN payment_webhook_events.gateway_event_id IS 'Gateway-assigned event ID. UNIQUE: INSERT ... ON CONFLICT DO NOTHING deduplicates re-deliveries.';
COMMENT ON COLUMN payment_webhook_events.payload          IS 'Raw webhook body. Never contains card data (PCI-DSS). Enables event replay on processing failures.';
COMMENT ON COLUMN payment_webhook_events.processed        IS 'FALSE = awaiting processing. Partial index on (processed=FALSE) for queue queries.';

-- Partial index: unprocessed webhook queue — what the webhook processor scans.
CREATE INDEX idx_webhooks_pending
    ON payment_webhook_events (created_at ASC)
    WHERE processed = FALSE;

CREATE INDEX idx_webhooks_gateway_type
    ON payment_webhook_events (gateway_id, event_type, created_at DESC);

-- SCALING GAP: this table is unpartitioned but is append-heavy and high-growth
-- (every gateway callback lands here). As volume grows, partition monthly by
-- created_at — see 016_audit_logs.sql as the reference pattern.
COMMENT ON TABLE payment_webhook_events IS
    'Raw inbound gateway webhook events (Tabby, Tamara). Stored before processing '
    'for idempotency and audit. Both gateways deliver here. '
    'SCALING GAP: this table is unpartitioned but is append-heavy and high-growth. '
    'As volume grows, partition monthly by created_at — see 016_audit_logs.sql '
    'as the reference pattern (backfill/cutover plan + pg_partman required).';

COMMIT;
