#!/usr/bin/env bash
# =============================================================================
# run-schema.sh
# Applies the PostgreSQL baseline schema in dependency order via Docker STDIN.
# =============================================================================

set -euo pipefail

PGSQL_SERVICE="${PGSQL_SERVICE:-pgsql}"
DATABASE="${PGDATABASE:-ecommerce}"
USERNAME="${PGUSER:-sail}"
PASSWORD="${PGPASSWORD:-}"
FORCE=false

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

while getopts "s:d:U:p:fr:" opt; do
    case $opt in
        s) PGSQL_SERVICE="$OPTARG" ;;
        d) DATABASE="$OPTARG" ;;
        U) USERNAME="$OPTARG" ;;
        p) PASSWORD="$OPTARG" ;;
        f) FORCE=true ;;
        r) PROJECT_DIR="$OPTARG" ;;
        *) echo "Unknown option: -$OPTARG" >&2; exit 1 ;;
    esac
done

SQL_DIR="$PROJECT_DIR/database/sql"

echo ""
echo "============================================================"
echo "  Commerce Single Vendor -- PostgreSQL Schema Installer"
echo "  Docker STDIN Pipe: no host psql or mounts required"
echo "============================================================"
echo "  Service:  $PGSQL_SERVICE"
echo "  Database: $DATABASE"
echo "  User:     $USERNAME"
echo "  Project:  $PROJECT_DIR"
echo "============================================================"
echo ""

psql_pipe() {
    local extra_args=()
    if [[ -n "$PASSWORD" ]]; then
        extra_args+=(-e "PGPASSWORD=$PASSWORD")
    fi
    docker compose exec -T "${extra_args[@]}" "$PGSQL_SERVICE" \
        psql -U "$USERNAME" -d "$DATABASE" -v ON_ERROR_STOP=1 "$@"
}

echo -n "[1/6] Checking Docker availability... "
if ! docker info > /dev/null 2>&1; then
    echo "FAILED"
    echo "ERROR: Docker is not running or not accessible." >&2
    exit 1
fi
echo "OK"

echo -n "[2/6] Checking '$PGSQL_SERVICE' container health... "
if ! docker compose ps "$PGSQL_SERVICE" 2>/dev/null | grep -qE "healthy|Up"; then
    echo "FAILED"
    echo "ERROR: '$PGSQL_SERVICE' container is not running or not healthy." >&2
    exit 1
fi
echo "healthy"

echo -n "[3/6] Verifying PostgreSQL connectivity... "
pg_ver=$(psql_pipe -Atc "SELECT version();" 2>&1)
if [[ $? -ne 0 ]]; then
    echo "FAILED"
    echo "ERROR: Cannot connect to PostgreSQL. Check USERNAME/PASSWORD." >&2
    exit 1
fi
echo "OK"
echo "         PostgreSQL: $pg_ver"

echo -n "[4/6] Checking database state... "
table_count=$(psql_pipe -Atc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';" 2>&1)

if [[ "$table_count" -gt 0 && "$FORCE" == "false" ]]; then
    echo "STOPPED"
    echo "WARNING: Database '$DATABASE' contains $table_count table(s). Use -f to force re-apply." >&2
    exit 0
fi

if [[ "$table_count" -gt 0 ]]; then
    echo "WARNING: $table_count existing tables -- applying with -f (force)"
else
    echo "empty (fresh)"
fi

SQL_FILES=(
    "001_extensions_enums.sql"
    "002_identity_auth.sql"
    "003_customers_addresses.sql"
    "004_categories.sql"
    "005_products_attributes_variants.sql"
    "006_inventory.sql"
    "007_cart.sql"
    "008_orders.sql"
    "009_shipping.sql"
    "010_payment_gateways.sql"
    "011_payments.sql"
    "012_installments_refunds.sql"
    "013_promotions.sql"
    "014_reviews.sql"
    "015_notifications.sql"
    "016_audit_logs.sql"
    "017_indexes.sql"
    "018_functions_triggers.sql"
    "019_materialized_views.sql"
    "021_seed_data.sql"
    "022_catalog_seed.sql"
)

echo "[5/6] Applying schema files (${#SQL_FILES[@]} files)..."
echo ""

applied=0
for sql_file in "${SQL_FILES[@]}"; do
    file_path="$SQL_DIR/$sql_file"
    if [[ ! -f "$file_path" ]]; then
        echo "FAILED"
        echo "ERROR: File not found: $file_path" >&2
        exit 1
    fi

    echo -n "  Applying: $sql_file ... "

    if ! psql_pipe --quiet < "$file_path"; then
        echo "FAILED"
        echo ""
        echo "ERROR: Schema application failed at: $sql_file" >&2
        exit 1
    fi

    applied=$((applied + 1))
    echo "done"
done

echo ""
echo -n "[6/6] Verifying object counts... "
final_count=$(psql_pipe -Atc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>&1)
echo "Tables in public schema: $final_count"

echo ""
echo "============================================================"
echo "  Schema applied successfully ($applied files)."
echo "  Next steps:"
echo "    1. Run: bash database/sql/verify-schema.sh"
echo "    2. Run: docker compose exec laravel.test php artisan about"
echo "============================================================"
echo ""
