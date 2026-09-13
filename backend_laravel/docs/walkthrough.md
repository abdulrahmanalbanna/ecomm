# Walkthrough — PostgreSQL Database Schema Enhancement

This walkthrough documents the **PostgreSQL Database Schema Enhancement** task: adding four new application tables (`business_settings`, `cities`, `brands`, `banners`), one new join/registry table (`wishlists`), their foreign keys, `updated_at` triggers, seed data, and automated schema verification coverage.

The raw SQL files in [`database/sql/`](database/sql/README.md:1) remain the **authoritative database schema**. No Laravel migrations were created or run — the enhancement is expressed entirely as edits to the baseline SQL migrations.

---

## Scope of Changes

| # | File | Change |
|---|------|--------|
| 1 | [`002_identity_auth.sql`](database/sql/002_identity_auth.sql:1) | Added `business_settings` table (key/value store) |
| 2 | [`003_customers_addresses.sql`](database/sql/003_customers_addresses.sql:1) | Added `cities` table + `addresses.city_id` FK |
| 3 | [`005_products_attributes_variants.sql`](database/sql/005_products_attributes_variants.sql:1) | Added `brands` table + `products.brand_id` FK |
| 4 | [`007_cart.sql`](database/sql/007_cart.sql:1) | Added `wishlists` table |
| 5 | [`013_promotions.sql`](database/sql/013_promotions.sql:1) | Added `banners` table |
| 6 | [`018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:1) | Attached `updated_at` triggers for all 4 new tables |
| 7 | [`021_seed_data.sql`](database/sql/021_seed_data.sql:1) | Seeded `business_settings`, `cities`, and new permissions |
| 8 | [`verify-schema.sql`](database/sql/verify-schema.sql:1) | Added new tables + triggers to automated verification |

---

## 1. `business_settings` — [`002_identity_auth.sql`](database/sql/002_identity_auth.sql:147)

A system-wide key/value configuration store for store info, feature flags, and business rules.

- `key` — `VARCHAR(100) UNIQUE NOT NULL`, constrained to `^[a-z0-9_.]+$`.
- `value` — `TEXT` (flexible; typed by the `type` column).
- `type` — `VARCHAR(30)` constrained to `('string','number','boolean','json','array')`.
- `is_public` — `BOOLEAN`; `TRUE` = safe to expose to the public/frontend API.
- `is_active` — `BOOLEAN`; `TRUE` = setting in effect.
- `created_at` / `updated_at` — timestamps.
- Partial index `idx_business_settings_key_active ON business_settings (key) WHERE is_active = TRUE`.

## 2. `cities` + `addresses.city_id` — [`003_customers_addresses.sql`](database/sql/003_customers_addresses.sql:47)

A reference table for supported shipping/delivery cities.

- `name` — `VARCHAR(150) NOT NULL`.
- `country_code` — `CHAR(2) NOT NULL DEFAULT 'SA'` (ISO 3166-1 alpha-2).
- `is_active` / `sort_order` — display/availability controls.
- `UNIQUE (country_code, name)` — prevents duplicate city names per country.
- Index `idx_cities_country_active ON cities (country_code, is_active, sort_order)`.

`addresses` gained an optional `city_id BIGINT REFERENCES cities(id) ON DELETE SET NULL` plus supporting index `idx_addresses_city_id`. The existing `city` free-text column is retained for snapshot/legacy compatibility.

## 3. `brands` + `products.brand_id` — [`005_products_attributes_variants.sql`](database/sql/005_products_attributes_variants.sql:258)

A product brand registry.

- `slug` — `VARCHAR(200) UNIQUE NOT NULL`, constrained to kebab-case `^[a-z0-9]+(-[a-z0-9]+)*$`.
- `name`, `logo_url`, `website_url`, `description`.
- `is_active` / `is_featured` — catalog filtering flags.
- Indexes: `idx_brands_slug`, `idx_brands_featured` (partial, featured + active).

`products` gained `brand_id BIGINT REFERENCES brands(id) ON DELETE SET NULL` plus index `idx_products_brand_id`. The legacy `products.brand` free-text column is retained.

## 4. `wishlists` — [`007_cart.sql`](database/sql/007_cart.sql:60)

A customer saved-items registry.

- `user_id` — `BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE`.
- `product_id` — `BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE`.
- `variant_id` — optional `BIGINT REFERENCES product_variants(id) ON DELETE CASCADE`.
- `added_at` — timestamp.
- Indexes: `idx_wishlists_user_id`, `idx_wishlists_product_id`.
- Two partial UNIQUE indexes prevent duplicate entries per user:
  - `uq_wishlists_user_product_null_variant ON wishlists (user_id, product_id) WHERE variant_id IS NULL`
  - `uq_wishlists_user_product_variant ON wishlists (user_id, product_id, variant_id) WHERE variant_id IS NOT NULL`

## 5. `banners` — [`013_promotions.sql`](database/sql/013_promotions.sql:99)

Promotional banner slides / marketing banners.

- `title`, `subtitle`, `image_url`, `mobile_image_url`, `link_url`.
- `target_type` / `target_id` — flexible deep-link target.
- `position` — display placement (e.g. `home_slider`).
- `sort_order`, `is_active`, `start_at` / `end_at` (validity window CHECK).
- `click_count` — engagement counter (`CHECK >= 0`).
- Index `idx_banners_position_active ON banners (position, is_active, sort_order)`.

## 6. `updated_at` Triggers — [`018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:130)

The universal `fn_set_updated_at()` trigger function is attached to all four new tables that carry an `updated_at` column:

- `trg_business_settings_updated_at` ON `business_settings`
- `trg_cities_updated_at` ON `cities`
- `trg_brands_updated_at` ON `brands`
- `trg_banners_updated_at` ON `banners`

Each uses `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER` for idempotent re-application)Skip `wishlists` — it has no `updated_at` column, so no trigger is required.

## 7. Seed Data — [`021_seed_data.sql`](database/sql/021_seed_data.sql:196)

- **`business_settings`** (9 rows): `store.name`, `store.phone`, `store.logo`, `store.footer_logo`, `store.fav_icon`, `store.copyright_text`, `store.currency`, `financial.tax_rate`, `system.maintenance_mode` — `ON CONFLICT (key) DO NOTHING`.
- **`cities`** (5 rows): Riyadh, Jeddah, Dammam, Mecca, Medina — `ON CONFLICT (country_code, name) DO NOTHING`.
- **New permissions** added to the RBAC seed:
  - `banners.view`, `banners.manage` (Promotions)
  - `brands.view`, `brands.manage` (Brands)
  - `self.wishlist.manage` (Customer self-service)
  - `settings.view`, `settings.manage` (System/settings)
- **Role assignments**: `admin` gets all permissions via `CROSS JOIN`; `staff` gains `brands.*` and `banners.*`; `customer` gains `self.wishlist.manage`.
- The confirmation `DO` block now also reports `business_settings` and `cities` counts.

## 8. Automated Verification — [`verify-schema.sql`](database/sql/verify-schema.sql:1)

The non-destructive verification script now covers the new objects:

- **Section 4 (Core Tables)**: added `business_settings`, `cities`, `brands`, `wishlists`, `banners` to the required-tables array.
- **Section 8 (Triggers)**: added `trg_business_settings_updated_at`, `trg_cities_updated_at`, `trg_brands_updated_at`, `trg_banners_updated_at` to the required-triggers array.
- **Section 12 (Seed Data)**: the `permissions >= 30` threshold is comfortably exceeded (43 seeded permission codes).

---

## Validation

### Static Validation (performed in this environment)

Docker and a host `psql` client were **not available** in this environment, so the live `verify-schema.sh` run could not be executed. A full static cross-check was performed instead, confirming every object the verification script expects is actually defined:

| Verification expectation | Source definition | Status |
|---|---|---|
| `business_settings` table | [`002_identity_auth.sql`](database/sql/002_identity_auth.sql:147) | ✅ |
| `cities` table | [`003_customers_addresses.sql`](database/sql/003_customers_addresses.sql:47) | ✅ |
| `addresses.city_id` FK | [`003_customers_addresses.sql`](database/sql/003_customers_addresses.sql:74) | ✅ |
| `brands` table | [`005_products_attributes_variants.sql`](database/sql/005_products_attributes_variants.sql:258) | ✅ |
| `products.brand_id` FK | [`005_products_attributes_variants.sql`](database/sql/005_products_attributes_variants.sql:313) | ✅ |
| `wishlists` table | [`007_cart.sql`](database/sql/007_cart.sql:60) | ✅ |
| `banners` table | [`013_promotions.sql`](database/sql/013_promotions.sql:99) | ✅ |
| `trg_business_settings_updated_at` | [`018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:130) | ✅ |
| `trg_cities_updated_at` | [`018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:135) | ✅ |
| `trg_brands_updated_at` | [`018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:140) | ✅ |
| `trg_banners_updated_at` | [`018_functions_triggers.sql`](database/sql/018_functions_triggers.sql:145) | ✅ |
| `business_settings` seed | [`021_seed_data.sql`](database/sql/021_seed_data.sql:199) | ✅ |
| `cities` seed | [`021_seed_data.sql`](database/sql/021_seed_data.sql:215) | ✅ |
| New permissions (43 total ≥ 30) | [`021_seed_data.sql`](database/sql/021_seed_data.sql:32) | ✅ |

### Live Verification (to run in the Docker environment)

Run the automated verification against a running PostgreSQL container:

```bash
# Linux / macOS
bash database/sql/verify-schema.sh

# Windows (PowerShell)
.\database\sql\verify-schema.ps1
```

Expected result: **FAIL = 0** (all new tables and triggers PASS; the only WARN is the pre-existing non-critical `pg_stat_statements` preload notice).

---

## Summary

All eight baseline SQL files were updated to introduce the four new tables, the `wishlists` registry, their foreign keys, `updated_at` triggers, seed data, and verification coverage. The schema remains fully expressed in raw PostgreSQL SQL — no Laravel migrations were introduced — and the automated verification script now guards the new objects alongside the existing baseline.