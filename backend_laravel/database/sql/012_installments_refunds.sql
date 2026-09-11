-- =============================================================================
-- Migration 012 : Installment Plans, Installments & Refunds
-- Project       : commerce-single-vendor
-- Tables        : installment_plans, installments, refunds
-- Depends on    : 011_payments.sql (payments, payment_transactions)
--                 002_identity_auth.sql (users)
-- Design notes  :
--   - installment_plans is 1:1 with payments (UNIQUE on payment_id).
--     Only created when payments.payment_method = 'installment'.
--   - Tabby:  4 equal installments (every 2 weeks / monthly — gateway-determined)
--   - Tamara: 3 or 6 equal installments (monthly)
--   - Each individual installment payment creates a new payment_attempts row
--     and a payment_transactions row when settled.
--   - refunds reference the original charge payment_transaction and the parent payment.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- INSTALLMENT PLANS
-- 1:1 with payments. Holds the plan-level metadata from the gateway.
-- Only exists when payments.payment_method = 'installment'.
-- ---------------------------------------------------------------------------
CREATE TABLE installment_plans (
    id                     BIGSERIAL   PRIMARY KEY,
    -- UNIQUE enforces 1:1 with payments.
    payment_id             BIGINT      UNIQUE NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    -- The gateway's own reference ID for this installment plan.
    gateway_plan_id        VARCHAR(200),
    -- Total number of installments (Tabby: 4, Tamara: 3 or 6).
    number_of_installments SMALLINT    NOT NULL CHECK (number_of_installments IN (3, 4, 6)),
    -- Amount per installment in SAR (usually total_amount / number_of_installments).
    installment_amount     NUMERIC(12,2) NOT NULL CHECK (installment_amount > 0),
    -- Date the first installment is due (typically today or on order confirmation).
    first_payment_date     DATE        NOT NULL,
    -- Payment frequency. Both Tabby and Tamara use monthly by default.
    frequency              VARCHAR(20) NOT NULL DEFAULT 'monthly'
                                       CHECK (frequency IN ('monthly', 'biweekly', 'weekly')),
    status                 VARCHAR(30) NOT NULL DEFAULT 'active'
                                       CHECK (status IN ('active', 'completed', 'defaulted', 'cancelled')),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE  installment_plans                      IS '1:1 with payments. Created only when payment_method = installment. Holds Tabby/Tamara plan metadata.';
COMMENT ON COLUMN installment_plans.number_of_installments IS 'CHECK: Tabby supports 4. Tamara supports 3 or 6. Only these values are valid.';
COMMENT ON COLUMN installment_plans.gateway_plan_id      IS 'Gateway''s plan reference. Used for querying plan status and triggering manual charges.';
COMMENT ON COLUMN installment_plans.installment_amount   IS 'Per-installment amount. Last installment may differ slightly due to rounding — gateway handles this.';

CREATE INDEX idx_installment_plans_payment
    ON installment_plans (payment_id);

-- ---------------------------------------------------------------------------
-- INSTALLMENTS
-- Individual installment payment records within a plan.
-- One row per scheduled payment period.
-- ---------------------------------------------------------------------------
CREATE TABLE installments (
    id                    BIGSERIAL   PRIMARY KEY,
    plan_id               BIGINT      NOT NULL REFERENCES installment_plans(id) ON DELETE RESTRICT,
    -- Sequential installment number within the plan: 1, 2, 3, ...
    installment_number    SMALLINT    NOT NULL CHECK (installment_number >= 1),
    amount                NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    due_date              DATE        NOT NULL,
    -- NULL = not yet paid. Set to gateway settlement timestamp on payment.
    paid_at               TIMESTAMPTZ,
    -- Gateway's transaction reference for this specific installment charge.
    gateway_transaction_id VARCHAR(200) UNIQUE,
    status                VARCHAR(30) NOT NULL DEFAULT 'pending'
                                      CHECK (status IN ('pending', 'paid', 'failed', 'overdue', 'waived')),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Composite UNIQUE: no two installments in a plan share the same number.
    CONSTRAINT uq_installment_number_per_plan UNIQUE (plan_id, installment_number)
);

COMMENT ON TABLE  installments                      IS 'Individual scheduled installment payments within a plan.';
COMMENT ON COLUMN installments.installment_number   IS '1-based sequence within the plan. UNIQUE per plan_id.';
COMMENT ON COLUMN installments.paid_at              IS 'NULL = not yet paid. Populated from gateway webhook on settlement.';
COMMENT ON COLUMN installments.gateway_transaction_id IS 'Gateway''s reference for this specific charge. Used for dispute resolution.';

CREATE INDEX idx_installments_plan
    ON installments (plan_id, installment_number);

-- For scheduled jobs: find all overdue or due-soon installments
CREATE INDEX idx_installments_due
    ON installments (due_date, status)
    WHERE status IN ('pending', 'failed');


-- ---------------------------------------------------------------------------
-- REFUNDS
-- Partial or full refund records against a payment.
-- A payment can have multiple refunds (sum must not exceed original amount).
-- ---------------------------------------------------------------------------
CREATE TABLE refunds (
    id                  BIGSERIAL    PRIMARY KEY,
    -- The parent payment being refunded.
    payment_id          BIGINT       NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    -- The specific settled charge transaction being reversed (for reconciliation tracing).
    transaction_id      BIGINT,
    -- Refund amount in SAR. Aggregate active refunds are serialized and capped by trigger.
    amount              NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    reason              VARCHAR(200),
    -- Gateway's refund reference ID. UNIQUE prevents duplicate refund recording.
    gateway_refund_id   VARCHAR(200) UNIQUE,
    status              VARCHAR(30)  NOT NULL DEFAULT 'pending'
                                     CHECK (status IN ('pending', 'processing', 'processed', 'failed')),
    -- Admin user who initiated the refund (for audit trail).
    initiated_by        BIGINT       REFERENCES users(id) ON DELETE SET NULL,
    processed_at        TIMESTAMPTZ,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT fk_refunds_payment_transaction
        FOREIGN KEY (payment_id, transaction_id)
        REFERENCES payment_transactions (payment_id, id)
        ON DELETE RESTRICT
);

COMMENT ON TABLE  refunds                   IS 'Partial or full refund records. Multiple refunds per payment are allowed; active totals are serialized and capped at payments.amount by trigger.';
COMMENT ON COLUMN refunds.gateway_refund_id IS 'UNIQUE: prevents double-recording. INSERT ... ON CONFLICT (gateway_refund_id) DO NOTHING in webhook handler.';
COMMENT ON COLUMN refunds.initiated_by      IS 'Admin user who triggered the refund. ON DELETE SET NULL preserves refund record if admin user is removed.';
COMMENT ON COLUMN refunds.transaction_id    IS 'Links to the specific charge transaction being reversed. Used for line-item reconciliation.';

CREATE INDEX idx_refunds_payment
    ON refunds (payment_id, created_at DESC);

CREATE INDEX idx_refunds_transaction_fk
    ON refunds (payment_id, transaction_id)
    WHERE transaction_id IS NOT NULL;

CREATE OR REPLACE FUNCTION fn_validate_installment_plan()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_method VARCHAR(20);
BEGIN
    SELECT payment_method INTO v_method
    FROM payments
    WHERE id = NEW.payment_id
    FOR KEY SHARE;

    IF v_method IS DISTINCT FROM 'installment' THEN
        RAISE EXCEPTION 'Installment plan requires an installment-method payment'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_installment_plans_validate_payment
    BEFORE INSERT OR UPDATE OF payment_id ON installment_plans
    FOR EACH ROW EXECUTE FUNCTION fn_validate_installment_plan();

CREATE OR REPLACE FUNCTION fn_validate_installment_plan_count()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_max_installment SMALLINT;
BEGIN
    SELECT MAX(installment_number) INTO v_max_installment
    FROM installments
    WHERE plan_id = NEW.id;

    IF v_max_installment > NEW.number_of_installments THEN
        RAISE EXCEPTION 'Plan count % is below existing installment number %',
            NEW.number_of_installments, v_max_installment
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_installment_plans_validate_count
    BEFORE UPDATE OF number_of_installments ON installment_plans
    FOR EACH ROW EXECUTE FUNCTION fn_validate_installment_plan_count();

CREATE OR REPLACE FUNCTION fn_validate_installment_number()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_count SMALLINT;
BEGIN
    SELECT number_of_installments INTO v_count
    FROM installment_plans
    WHERE id = NEW.plan_id
    FOR UPDATE;

    IF NEW.installment_number > v_count THEN
        RAISE EXCEPTION 'Installment number % exceeds plan count %',
            NEW.installment_number, v_count
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_installments_validate_number
    BEFORE INSERT OR UPDATE OF plan_id, installment_number ON installments
    FOR EACH ROW EXECUTE FUNCTION fn_validate_installment_number();

CREATE OR REPLACE FUNCTION fn_validate_refund_total()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_payment_amount NUMERIC(12,2);
    v_refund_total   NUMERIC(12,2);
BEGIN
    SELECT amount INTO v_payment_amount
    FROM payments
    WHERE id = NEW.payment_id
    FOR UPDATE;

    SELECT COALESCE(SUM(amount), 0) INTO v_refund_total
    FROM refunds
    WHERE payment_id = NEW.payment_id
      AND status IN ('pending', 'processing', 'processed')
      AND (TG_OP = 'INSERT' OR id <> NEW.id);

    IF NEW.status IN ('pending', 'processing', 'processed') THEN
        v_refund_total := v_refund_total + NEW.amount;
    END IF;

    IF v_refund_total > v_payment_amount THEN
        RAISE EXCEPTION 'Refund total % exceeds payment amount % for payment_id %',
            v_refund_total, v_payment_amount, NEW.payment_id
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_refunds_validate_total
    BEFORE INSERT OR UPDATE OF payment_id, amount, status ON refunds
    FOR EACH ROW EXECUTE FUNCTION fn_validate_refund_total();

COMMIT;
