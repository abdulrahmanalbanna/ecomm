-- =============================================================================
-- Migration 003 : Customer Profiles, Addresses & Cities
-- Project       : commerce-single-vendor
-- Tables        : customer_profiles, cities, addresses
-- Depends on    : 002_identity_auth.sql (users)
-- Design note   : customer_profiles is 1:1 with users (auth table stays lean).
--                 Addresses use soft delete to preserve order snapshot history.
--                 Cities provides structured geographic reference data for shipping.
--                 GiST/PostGIS geolocation column: CONFIRMED deferred to Phase 2.
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- CUSTOMER PROFILES
-- Extends users with profile data. Kept separate to keep the auth table lean.
-- ---------------------------------------------------------------------------
CREATE TABLE customer_profiles (
    id            BIGSERIAL    PRIMARY KEY,
    -- UNIQUE enforces the 1:1 relationship with users at the database level.
    user_id       BIGINT       UNIQUE NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    date_of_birth DATE,
    gender        VARCHAR(20),
    avatar_url    TEXT,
    -- Flexible JSONB for per-customer preferences.
    -- Example schema: {
    --   "language": "ar",
    --   "currency_display": "SAR",
    --   "notifications": { "email": true, "sms": false, "push": true }
    -- }
    -- No GIN index at MVP scale — not filtered in WHERE clauses yet.
    preferences   JSONB        NOT NULL DEFAULT '{}',
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  customer_profiles             IS '1:1 extension of users. Profile data separated from auth table for single-responsibility.';
COMMENT ON COLUMN customer_profiles.user_id     IS 'UNIQUE: enforces 1:1 with users. CASCADE: profile deleted when account is deleted.';
COMMENT ON COLUMN customer_profiles.preferences IS 'Schema-free JSONB: notification channels, language, display preferences.';

-- ---------------------------------------------------------------------------
-- CITIES
-- Reference table for active shipping/delivery cities.
-- ---------------------------------------------------------------------------
CREATE TABLE cities (
    id           BIGSERIAL    PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    country_code CHAR(2)      NOT NULL DEFAULT 'SA',
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order   INT          NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT cities_name_country_key UNIQUE (country_code, name),
    CONSTRAINT cities_sort_order_check CHECK (sort_order >= 0)
);

COMMENT ON TABLE  cities              IS 'Reference table for supported delivery cities.';
COMMENT ON COLUMN cities.country_code IS 'ISO 3166-1 alpha-2 country code (e.g. SA).';
COMMENT ON COLUMN cities.sort_order   IS 'Display sorting index (>= 0).';

CREATE INDEX idx_cities_country_active ON cities (country_code, is_active, sort_order);

-- ---------------------------------------------------------------------------
-- ADDRESSES
-- Customer address book. Orders preserve independent JSONB snapshots, so address
-- rows may be hard-deleted with the owning account without losing order history.
-- ---------------------------------------------------------------------------
CREATE TABLE addresses (
    id             BIGSERIAL    PRIMARY KEY,
    user_id        BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    city_id        BIGINT       REFERENCES cities(id) ON DELETE SET NULL,
    label          VARCHAR(50),
    recipient_name VARCHAR(200) NOT NULL,
    phone          VARCHAR(30),
    line1          TEXT         NOT NULL,
    line2          TEXT,
    city           VARCHAR(100) NOT NULL,
    state          VARCHAR(100),
    postal_code    VARCHAR(20),
    country_code   CHAR(2)      NOT NULL,
    is_default     BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
    -- PHASE 2 PLACEHOLDER:
    -- location GEOMETRY(Point, 4326),  -- PostGIS point for delivery zone calculation
    -- GiST index: CREATE INDEX ON addresses USING GIST (location);
);

COMMENT ON TABLE  addresses              IS 'Customer address book. Orders retain independent address snapshots; rows cascade only when the owning user is hard-deleted.';
COMMENT ON COLUMN addresses.city_id      IS 'Optional foreign key to cities reference table.';
COMMENT ON COLUMN addresses.updated_at   IS 'Last-modification timestamp. Maintained by trg_addresses_updated_at via '
                                              'fn_set_updated_at() (018). Required — trg_addresses_updated_at throws '
                                              '''record new has no field updated_at'' on every UPDATE if this column is missing.';
COMMENT ON COLUMN addresses.label        IS 'Display label: Home, Work, Other. Customer-chosen nickname.';
COMMENT ON COLUMN addresses.is_default   IS 'At most ONE default per user enforced by partial unique index below.';
COMMENT ON COLUMN addresses.country_code IS 'ISO 3166-1 alpha-2. SA = Saudi Arabia (primary market for SAR currency).';

-- address lookup per user
CREATE INDEX idx_addresses_user ON addresses (user_id);
CREATE INDEX idx_addresses_city_id ON addresses (city_id);

-- Partial UNIQUE index: enforces at most ONE default address per user.
CREATE UNIQUE INDEX idx_addresses_one_default_per_user ON addresses (user_id) WHERE is_default = TRUE;

-- ---------------------------------------------------------------------------
-- DEFAULT ADDRESS GUARD (issue 3.7)
-- When any row is set as is_default = TRUE, atomically unset all other rows
-- for the same user. This provides single-writer semantics without relying on
-- application-layer discipline. The partial unique index above is the final
-- DB-level guard; this trigger prevents reaching the 23505 error path.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_addresses_default_guard()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.is_default = TRUE THEN
        -- Atomically clear is_default on all other addresses for this user.
        -- This runs inside the same transaction and fires before the unique
        -- index is evaluated, so the 23505 path is never reached.
        UPDATE addresses
        SET is_default = FALSE
        WHERE user_id = NEW.user_id
          AND id <> NEW.id
          AND is_default = TRUE;
    END IF;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_addresses_default_guard() IS
    'BEFORE INSERT OR UPDATE OF is_default trigger. When is_default is set to TRUE, '
    'atomically clears is_default on all other addresses for the same user. '
    'Ensures the partial unique index (user_id WHERE is_default = TRUE) is never '
    'violated; eliminates the 23505 error path for well-formed requests.';

CREATE TRIGGER trg_addresses_default_guard
    BEFORE INSERT OR UPDATE OF is_default ON addresses
    FOR EACH ROW EXECUTE FUNCTION fn_addresses_default_guard();

COMMIT;
