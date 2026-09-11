-- =============================================================================
-- Migration 010 : Payment Gateways
-- Project       : commerce-single-vendor
-- Tables        : payment_gateways
-- Depends on    : 001_extensions_enums.sql
-- Design notes  :
--   - CONFIRMED gateways: Tabby (4 installments) and Tamara (3 or 6 installments).
--   - Both gateways support installment payments.
--   - config JSONB stores non-sensitive configuration only.
--   - NEVER store API keys, secrets, or tokens in this table.
--     Store those in environment variables or a secrets manager (e.g. AWS Secrets Manager).
--   - Seed data is in this file (small, static reference table).
-- =============================================================================

BEGIN;

CREATE TABLE payment_gateways (
    id                     BIGSERIAL    PRIMARY KEY,
    -- Machine-readable code used by the application to select the gateway processor.
    -- Seeded values: 'tabby', 'tamara'
    code                   VARCHAR(50)  UNIQUE NOT NULL,
    name                   VARCHAR(100) NOT NULL,
    -- Both confirmed gateways support installments.
    supports_installments  BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active              BOOLEAN      NOT NULL DEFAULT TRUE,
    -- Non-sensitive gateway configuration.
    -- Schema (per gateway):
    --   {
    --     "api_version":          "v2",           -- gateway API version string
    --     "webhook_path":         "/webhooks/tabby", -- internal route for inbound events
    --     "installment_options":  [4],             -- supported installment counts
    --     "checkout_url":         "https://api.tabby.ai/api/v2/checkout"
    --   }
    -- API keys and secrets are NEVER stored here. Use environment variables.
    config                 JSONB        NOT NULL DEFAULT '{}',
    created_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  payment_gateways                    IS 'Payment gateway registry. Seeded with Tabby and Tamara. Non-sensitive config only.';
COMMENT ON COLUMN payment_gateways.code               IS 'Machine name used in application payment processor dispatch: tabby, tamara.';
COMMENT ON COLUMN payment_gateways.supports_installments IS 'TRUE for both Tabby (4 payments) and Tamara (3 or 6 payments).';
COMMENT ON COLUMN payment_gateways.config             IS 'Non-sensitive config JSONB. Keys: api_version, webhook_path, installment_options, checkout_url. NEVER store secrets here.';
-- ---------------------------------------------------------------------------
-- SEED DATA
-- Tabby and Tamara are the confirmed gateways (both support installments).
-- ---------------------------------------------------------------------------
INSERT INTO payment_gateways (code, name, supports_installments, config) VALUES
(
    'tabby',
    'Tabby',
    TRUE,
    '{
        "api_version":         "v2",
        "webhook_path":        "/webhooks/tabby",
        "installment_options": [4],
        "checkout_url":        "https://api.tabby.ai/api/v2/checkout",
        "currency":            "SAR",
        "merchant_urls": {
            "success": "/checkout/success",
            "cancel":  "/checkout/cancel",
            "failure": "/checkout/failure"
        }
    }'
),
(
    'tamara',
    'Tamara',
    TRUE,
    '{
        "api_version":         "v1",
        "webhook_path":        "/webhooks/tamara",
        "installment_options": [3, 6],
        "checkout_url":        "https://api.tamara.co/checkout",
        "currency":            "SAR",
        "merchant_urls": {
            "success": "/checkout/success",
            "cancel":  "/checkout/cancel",
            "failure": "/checkout/failure"
        }
    }'
)
ON CONFLICT (code) DO UPDATE
SET name = EXCLUDED.name,
    supports_installments = EXCLUDED.supports_installments,
    config = EXCLUDED.config,
    updated_at = now();

COMMIT;
