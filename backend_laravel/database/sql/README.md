# PostgreSQL Schema — `database/sql/`

This directory contains the raw PostgreSQL SQL files that define the complete
baseline database schema for Commerce Single Vendor.

---

## Source of Truth

**These SQL files are the authoritative database schema.**

Do NOT convert them to Laravel migrations. The schema uses PostgreSQL-specific
features that cannot be expressed through Laravel's generic migration API:

| Feature | File(s) |
|---|---|
| Extensions (`citext`, `ltree`, `pgcrypto`, `pg_stat_statements`) | `001_extensions_enums.sql` |
| ENUM types (`order_status_enum`, `payment_status_enum`) | `001_extensions_enums.sql` |
| RANGE-partitioned tables (monthly) | `006`, `008`, `011`, `016` |
| PL/pgSQL functions & triggers | `018_functions_triggers.sql` |
| Materialized views | `019_materialized_views.sql` |
| GIN / GiST / BRIN / partial indexes | `017_indexes.sql` |

Laravel migrations in `database/migrations/` are reserved for **future
incremental changes** after the baseline schema is established.

---

## Docker Environment

The project uses **Laravel Sail** (Docker Compose).

| Service | Role | Docker image |
|---|---|---|
| `laravel.test` | Laravel / PHP 8.4 app | `sail-8.4/app` |
| `pgsql` | PostgreSQL database | `postgres:16-alpine` |
| `redis` | Cache / queue | `redis:alpine` |
| `mailpit` | Mail catcher | `axllent/mailpit` |

### Networking

- Inside the Docker `sail` network, Laravel connects to PostgreSQL using the
  service name `pgsql` on port `5432`.
- On the host, PostgreSQL is reachable at `localhost:5433` (mapped port).
- The connection is configured in `.env` via `DB_HOST=pgsql`.

### Starting the environment

```bash
docker compose up -d
docker compose ps              # confirm all services healthy
```

### Confirming Laravel ↔ PostgreSQL connectivity

```bash
docker compose exec laravel.test php artisan about
# Driver field must show: pgsql
```

---

## SQL File Execution Order

Files **must be applied in numeric order**. Each file depends on the previous.

```
001_extensions_enums.sql         — Extensions (citext, ltree, pgcrypto, pg_stat_statements)
                                   + ENUM types (order_status_enum, payment_status_enum)
002_identity_auth.sql            — roles, permissions, role_permissions, users,
                                   sessions, password_reset_tokens
003_customers_addresses.sql      — customer_profiles (1:1 with users), addresses
004_categories.sql               — categories (ltree hierarchy)
005_products_attributes_variants.sql — products, attribute_definitions, product_variants
006_inventory.sql                — inventory, inventory_movements (RANGE-partitioned)
007_cart.sql                     — carts, cart_items
008_orders.sql                   — orders (RANGE-partitioned), order_items, order_events
009_shipping.sql                 — shipping_methods, shipments
010_payment_gateways.sql         — payment_gateways + seed data (Tabby, Tamara)
011_payments.sql                 — payments, payment_attempts, payment_transactions,
                                   payment_webhook_events
012_installments_refunds.sql     — installment_plans, installments, refunds
013_promotions.sql               — coupons, coupon_usages
014_reviews.sql                  — product_reviews
015_notifications.sql            — notifications
016_audit_logs.sql               — audit_logs (RANGE-partitioned)
017_indexes.sql                  — Supplemental cross-domain indexes (run after tables)
018_functions_triggers.sql       — PL/pgSQL functions + triggers (run after all tables)
019_materialized_views.sql       — Materialized views (run after tables + indexes)
021_seed_data.sql                — Reference data: roles, permissions, shipping methods
```

---

## Applying the Schema

### Prerequisites

1. Docker Desktop running.
2. All Compose services started (`docker compose up -d`).
3. PostgreSQL container healthy (`docker compose ps`).

No host PostgreSQL client is required. `psql` runs inside the `pgsql` container.

### Windows (PowerShell) — from project root

```powershell
.\database\sql\run-schema.ps1
```

With explicit parameters:

```powershell
.\database\sql\run-schema.ps1 -Database ecommerce -Username sail -Password <password>
```

### Linux / macOS (Bash) — from project root

```bash
bash database/sql/run-schema.sh
bash database/sql/run-schema.sh -d ecommerce -U sail -p <password>
```

### Safety Guard

The scripts will **stop without applying any changes** if the target database
already contains application tables. This prevents accidental re-application.

To override (e.g., force re-apply to an already-populated database):

```powershell
.\database\sql\run-schema.ps1 -Force
```

```bash
bash database/sql/run-schema.sh -f
```

### Fresh Rebuild (Development Only — Manual)

Running these commands destroys and recreates the database:

```bash
docker compose exec -T pgsql psql -U sail -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'ecommerce' AND pid <> pg_backend_pid();"
docker compose exec -T pgsql psql -U sail -d postgres -c "DROP DATABASE IF EXISTS ecommerce;"
docker compose exec -T pgsql psql -U sail -d postgres -c "CREATE DATABASE ecommerce OWNER sail;"
.\database\sql\run-schema.ps1          # Windows
bash database/sql/run-schema.sh        # Linux/macOS
```

**Never run `DROP DATABASE` on a production database.**

---

## Verifying the Schema

### Windows (PowerShell)

```powershell
.\database\sql\verify-schema.ps1
```

### Linux / macOS (Bash)

```bash
bash database/sql/verify-schema.sh
```

The verification script:

- Runs `verify-schema.sql` inside the `pgsql` Docker container.
- Checks every required named object against PostgreSQL system catalogs.
- Reports `[PASS]`, `[WARN]`, `[FAIL]` per check.
- Exits with code `0` on full pass, `3` on any required failure.

Verifications include: extensions, enum types, core tables, partitioned tables,
child partitions, functions, triggers, materialized views, RLS absence,
key indexes, and seed data.

---

## PostgreSQL Features

### Partitioned Tables

| Table | Strategy | Key |
|---|---|---|
| `inventory_movements` | RANGE monthly | `created_at` |
| `orders` | RANGE monthly | `placed_at` |
| `order_events` | RANGE monthly | `created_at` |
| `audit_logs` | RANGE monthly | `created_at` |

Monthly partitions are pre-created for 2025–2027. A `_default` partition catches
rows outside the defined range. Future partition management should use **pg_partman**.

### Functions

| Function | Purpose |
|---|---|
| `fn_set_updated_at()` | Universal `updated_at` maintenance trigger |
| `fn_update_product_search_vector()` | TSVECTOR maintenance for `products` |
| `fn_update_review_search_vector()` | TSVECTOR maintenance for `product_reviews` |
| `fn_validate_order_status_transition()` | Order state machine guard trigger |
| `fn_reserve_inventory(variant_id, qty)` | FOR UPDATE + reserve stock |
| `fn_deduct_inventory(variant_id, qty)` | FOR UPDATE + deduct stock on fulfilment |
| `fn_release_inventory(variant_id, qty)` | FOR UPDATE + release reservation on cancel |

### Materialized Views

| View | Refresh Frequency | CONCURRENTLY |
|---|---|---|
| `mv_daily_sales` | Daily at 01:05 | ✅ (UNIQUE on `sale_date`) |
| `mv_monthly_revenue` | Monthly on 1st | ✅ (UNIQUE on `month`) |
| `mv_product_performance` | Daily at 02:30 | ✅ (UNIQUE on `product_id`) |
| `mv_inventory_status` | Every 15 min | ✅ (UNIQUE on `variant_id`) |
| `mv_category_performance` | Daily at 03:00 | ✅ (UNIQUE on `category_id`) |

Refresh command example:

```sql
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_daily_sales;
```

Use `pg_cron` for automated refresh scheduling (future task).

### Authorization

PostgreSQL Row-Level Security (RLS) has been removed from the baseline database schema.
Authorization will be handled at the Laravel application layer.

---

## `pg_stat_statements` Extension

### Status Levels

| Level | Meaning |
|---|---|
| **Creatable** | `CREATE EXTENSION` succeeds in PostgreSQL |
| **Installed** | Extension object exists in `pg_extension` |
| **Preloaded** | `shared_preload_libraries` includes `pg_stat_statements` |
| **Operational** | `SELECT * FROM pg_stat_statements` works; statistics are collecting |

### Configuration (already applied in `compose.yaml`)

`compose.yaml` includes:

```yaml
command: postgres -c shared_preload_libraries=pg_stat_statements
```

This makes the extension **operational** after a container restart.

If you change this value, restart the container:

```bash
docker compose restart pgsql
```

A full container rebuild is **not** required — restarting is sufficient.

---

## Environment Variables

Configure these in `.env` (never in `.env.example` with real values):

```env
DB_CONNECTION=pgsql
DB_HOST=pgsql          # Docker service name — not 127.0.0.1
DB_PORT=5432           # Container-internal port
DB_DATABASE=ecommerce
DB_USERNAME=           # Set in .env, not here
DB_PASSWORD=           # Set in .env, not here
DB_SCHEMA=public
DB_SSLMODE=prefer
FORWARD_DB_PORT=5433   # Host port mapping (for external clients: localhost:5433)
```

---

## Laravel Migrations

Existing Laravel migrations in `database/migrations/` (`create_users_table`,
`create_cache_table`, `create_jobs_table`) conflict with the baseline SQL schema
because `users`, `sessions`, and `password_reset_tokens` are defined in the SQL
files with different columns.

**Do not run `php artisan migrate` on the PostgreSQL database.**

The SQL schema is the source of truth. Future incremental changes should be
added as new Laravel migrations that are designed to complement the existing
baseline, not recreate it.
