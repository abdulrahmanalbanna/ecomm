-- =============================================================================
-- Migration 015 : Notifications
-- Project       : commerce-single-vendor
-- Tables        : notifications
-- Depends on    : 002_identity_auth.sql (users)
-- Design notes  :
--   - Notifications are queued here, then dispatched by a background worker.
--   - Partial index on (sent_at IS NULL) serves as a lightweight outbox queue.
--   - data JSONB holds template variables (order number, tracking number, etc.)
--     so the notification body can be rendered without extra DB queries.
--   - Channels: email, sms, push — each notification targets exactly one channel.
-- =============================================================================

BEGIN;

CREATE TABLE notifications (
    id         BIGSERIAL    PRIMARY KEY,
    user_id    BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    -- Event type code, maps to a notification template.
    -- Examples: order_confirmed, order_shipped, installment_due, review_approved
    type       VARCHAR(80)  NOT NULL,
    -- Delivery channel for this notification record.
    channel    VARCHAR(20)  NOT NULL CHECK (channel IN ('email', 'sms', 'push')),
    title      TEXT,
    body       TEXT,
    -- Template variables for rendering the notification content.
    -- Example: { "order_number": "ORD-1234", "tracking_url": "...", "amount": "150.00" }
    data       JSONB        NOT NULL DEFAULT '{}',
    -- NULL = not yet sent (queued). Populated by the background dispatcher on send.
    sent_at    TIMESTAMPTZ,
    -- NULL = not yet read. Populated when customer opens the notification (push/in-app).
    read_at    TIMESTAMPTZ,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  notifications       IS 'Notification outbox. Dispatched by background worker. Partial index on sent_at IS NULL for queue scan.';
COMMENT ON COLUMN notifications.type  IS 'Event code mapping to a notification template. e.g. order_confirmed, installment_due.';
COMMENT ON COLUMN notifications.data  IS 'JSONB template variables. Pre-loaded to avoid extra DB queries during dispatch.';
COMMENT ON COLUMN notifications.sent_at IS 'NULL = queued (not yet sent). Background worker sets this on successful dispatch.';

-- Partial index: unsent notification queue (lightweight outbox pattern)
CREATE INDEX idx_notifications_unsent_queue
    ON notifications (created_at ASC)
    WHERE sent_at IS NULL;

-- Per-user notification inbox (customer notification center)
CREATE INDEX idx_notifications_user_inbox
    ON notifications (user_id, created_at DESC);

-- Unread count query per user
CREATE INDEX idx_notifications_unread
    ON notifications (user_id)
    WHERE read_at IS NULL AND sent_at IS NOT NULL;

-- SCALING GAP: this table is unpartitioned but is append-heavy and high-growth
-- (one row per per-user notification event). As volume grows, partition monthly
-- by created_at — see 016_audit_logs.sql as the reference pattern.
COMMENT ON TABLE notifications IS
    'Notification outbox. Dispatched by background worker. '
    'SCALING GAP: this table is unpartitioned but is append-heavy and high-growth. '
    'As volume grows, partition monthly by created_at — see 016_audit_logs.sql '
    'as the reference pattern (backfill/cutover plan + pg_partman required).';

COMMIT;
