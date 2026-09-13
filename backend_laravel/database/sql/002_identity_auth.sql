-- =============================================================================
-- Migration 002 : Identity & Authentication & Business Settings
-- Project       : commerce-single-vendor
-- Tables        : roles, permissions, role_permissions, users,
--                 sessions, password_reset_tokens, business_settings
-- Depends on    : 001_extensions_enums.sql (citext, pgcrypto)
-- =============================================================================

BEGIN;

-- ---------------------------------------------------------------------------
-- ROLES
-- RBAC role definitions. Seeded in 021_seed_data.sql with: admin, staff, customer.
-- ---------------------------------------------------------------------------
CREATE TABLE roles (
    id          BIGSERIAL    PRIMARY KEY,
    name        VARCHAR(50)  UNIQUE NOT NULL,
    description TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  roles      IS 'RBAC role registry. Seeded rows: admin, staff, customer. Extend as needed.';
COMMENT ON COLUMN roles.name IS 'Machine-readable identifier, e.g. admin, staff, customer. Used in application guards.';

-- ---------------------------------------------------------------------------
-- PERMISSIONS
-- Granular permission codes assigned to roles via role_permissions.
-- ---------------------------------------------------------------------------
CREATE TABLE permissions (
    id          BIGSERIAL    PRIMARY KEY,
    code        VARCHAR(100) UNIQUE NOT NULL,
    description TEXT
);

COMMENT ON TABLE  permissions      IS 'Granular permission codes for RBAC. Seeded in 021_seed_data.sql.';
COMMENT ON COLUMN permissions.code IS 'Dot-notation: resource.action — e.g. orders.view, products.create, users.delete.';

-- ---------------------------------------------------------------------------
-- ROLE_PERMISSIONS (M2M join)
-- Composite PK enforces uniqueness; cascade on role/permission deletion.
-- ---------------------------------------------------------------------------
CREATE TABLE role_permissions (
    role_id       BIGINT NOT NULL REFERENCES roles(id)       ON DELETE CASCADE,
    permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

-- ---------------------------------------------------------------------------
-- ROLE_PERMISSIONS INDEX
-- Index to optimize lookups by permission_id (e.g., find all roles that have a specific permission).
-- ---------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_role_permissions_permission_id ON role_permissions (permission_id);

COMMENT ON TABLE role_permissions IS 'M2M: which permissions are granted to which roles.';

-- ---------------------------------------------------------------------------
-- USERS
-- Core authentication table. Kept lean — profile data lives in customer_profiles.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id                BIGSERIAL   PRIMARY KEY,
    -- public_id is the only identifier exposed to external APIs or URLs.
    -- Internal BIGSERIAL id never leaves the database layer.
    public_id         UUID        UNIQUE NOT NULL DEFAULT gen_random_uuid(),
    -- CITEXT enforces case-insensitive uniqueness without lower() overhead.
    email             CITEXT      UNIQUE NOT NULL,
    -- E.164 normalized phone number. Optional; used for OTP/SMS auth.
    phone             VARCHAR(30),
    -- ONLY store hashed passwords. Use bcrypt (cost >= 12) or argon2id.
    -- Application must NEVER log or expose this column.
    password_hash     TEXT        NOT NULL,
    role_id           BIGINT      NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
    is_active         BOOLEAN     NOT NULL DEFAULT TRUE,
    is_email_verified BOOLEAN     NOT NULL DEFAULT FALSE,
    last_login_at     TIMESTAMPTZ,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Soft delete: rows are never hard-deleted to preserve audit history and order FK integrity.
    deleted_at        TIMESTAMPTZ
);

COMMENT ON TABLE  users                IS 'Core auth table. Contains credentials and role only. Profile data is in customer_profiles.';
COMMENT ON COLUMN users.public_id      IS 'UUID exposed to clients via APIs. Internal BIGSERIAL id is never exposed.';
COMMENT ON COLUMN users.email          IS 'CITEXT: case-insensitive unique constraint. Avoids lower() on every login query.';
COMMENT ON COLUMN users.password_hash  IS 'NEVER store plaintext. bcrypt cost >= 12 or argon2id. Application must not log this column.';
COMMENT ON COLUMN users.deleted_at     IS 'Soft delete. NULL = active. Rows preserved for order and audit FK integrity.';
COMMENT ON COLUMN users.role_id        IS 'ON DELETE RESTRICT: cannot delete a role that has users assigned.';

-- Active user lookup (login path — most frequent auth query)
CREATE INDEX idx_users_active_email
    ON users (email)
    WHERE is_active = TRUE AND deleted_at IS NULL;

-- Role-based filtering for admin queries
CREATE INDEX idx_users_role_id ON users (role_id);

-- ---------------------------------------------------------------------------
-- SESSIONS
-- Refresh token store. Tokens are hashed; raw token is held only by the client.
-- ---------------------------------------------------------------------------
CREATE TABLE sessions (
    id          BIGSERIAL   PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    -- Store SHA-256 hash of the refresh token only. Raw token = client's secret.
    token_hash  TEXT        UNIQUE NOT NULL,
    ip_address  INET,
    user_agent  TEXT,
    expires_at  TIMESTAMPTZ NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- NULL = active session. Set to now() on logout or token rotation.
    revoked_at  TIMESTAMPTZ
);

COMMENT ON TABLE  sessions            IS 'Refresh token sessions. Raw tokens are never stored — only SHA-256 hashes.';
COMMENT ON COLUMN sessions.token_hash IS 'SHA-256(raw_refresh_token). Client holds the raw token; DB holds the hash.';
COMMENT ON COLUMN sessions.revoked_at IS 'NULL = active. Set on logout, password change, or token rotation.';

CREATE INDEX idx_sessions_user_id ON sessions (user_id);

-- Partial index covers only active sessions — what the application queries 99% of the time.
-- Avoids scanning revoked rows.
CREATE INDEX idx_sessions_active_token ON sessions (token_hash) WHERE revoked_at IS NULL;

-- ---------------------------------------------------------------------------
-- PASSWORD RESET TOKENS
-- Single-use, time-limited tokens for the password reset flow.
-- ---------------------------------------------------------------------------
CREATE TABLE password_reset_tokens (
    id          BIGSERIAL   PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  TEXT        UNIQUE NOT NULL,
    expires_at  TIMESTAMPTZ NOT NULL,
    -- NULL = unused. Set to now() when the token is consumed.
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE  password_reset_tokens         IS 'Single-use password reset tokens. Invalidated on use or expiry.';
COMMENT ON COLUMN password_reset_tokens.used_at IS 'NULL = token still valid. Application must check used_at IS NULL AND expires_at > now().';

CREATE INDEX idx_prt_user_id ON password_reset_tokens (user_id);

-- ---------------------------------------------------------------------------
-- BUSINESS SETTINGS
-- System-wide key-value configuration store.
-- ---------------------------------------------------------------------------
CREATE TABLE business_settings (
    id          BIGSERIAL    PRIMARY KEY,
    key         VARCHAR(100) UNIQUE NOT NULL,
    value       TEXT,
    type        VARCHAR(30)  NOT NULL DEFAULT 'string',
    is_public   BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    description TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),

    CONSTRAINT business_settings_key_format_check
        CHECK (key ~ '^[a-z0-9_.]+$'),
    CONSTRAINT business_settings_type_check
        CHECK (type IN ('string', 'number', 'boolean', 'json', 'array'))
);

COMMENT ON TABLE  business_settings             IS 'Global system configuration store for store info, feature flags, and business rules.';
COMMENT ON COLUMN business_settings.key         IS 'Dot-notation or snake_case key (e.g., store.name, store.phone, store.logo). UNIQUE.';
COMMENT ON COLUMN business_settings.is_public   IS 'TRUE = safe to expose to public/frontend API; FALSE = backend internal only.';
COMMENT ON COLUMN business_settings.is_active   IS 'TRUE = setting active and in effect; FALSE = disabled/inactive setting.';

CREATE INDEX idx_business_settings_key_active ON business_settings (key) WHERE is_active = TRUE;

COMMIT;
