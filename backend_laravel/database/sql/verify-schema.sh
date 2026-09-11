#!/usr/bin/env bash
# =============================================================================
# verify-schema.sh
# Commerce Single Vendor -- PostgreSQL Schema Verification (Docker STDIN)
# =============================================================================

set -euo pipefail

PGSQL_SERVICE="${PGSQL_SERVICE:-pgsql}"
DATABASE="${PGDATABASE:-ecommerce}"
USERNAME="${PGUSER:-sail}"
PASSWORD="${PGPASSWORD:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

while getopts "s:d:U:p:r:" opt; do
    case $opt in
        s) PGSQL_SERVICE="$OPTARG" ;;
        d) DATABASE="$OPTARG" ;;
        U) USERNAME="$OPTARG" ;;
        p) PASSWORD="$OPTARG" ;;
        r) PROJECT_DIR="$OPTARG" ;;
        *) echo "Unknown option: -$OPTARG" >&2; exit 1 ;;
    esac
done

VERIFY_FILE="$PROJECT_DIR/database/sql/verify-schema.sql"

echo ""
echo "============================================================"
echo "  Commerce Single Vendor -- Schema Verification"
echo "  Docker STDIN Pipe: no host psql required"
echo "============================================================"
echo "  Service:  $PGSQL_SERVICE"
echo "  Database: $DATABASE"
echo "  User:     $USERNAME"
echo "============================================================"
echo ""

echo -n "[1/3] Checking Docker availability... "
if ! docker info > /dev/null 2>&1; then
    echo "FAILED"
    echo "ERROR: Docker is not running." >&2
    exit 1
fi
echo "OK"

echo -n "[2/3] Checking '$PGSQL_SERVICE' container... "
if ! docker compose ps "$PGSQL_SERVICE" 2>/dev/null | grep -qE "healthy|Up"; then
    echo "FAILED"
    echo "ERROR: '$PGSQL_SERVICE' container is not running. Run: docker compose up -d" >&2
    exit 1
fi
echo "healthy"

echo "[3/3] Running schema verification..."
echo ""

if [[ ! -f "$VERIFY_FILE" ]]; then
    echo "ERROR: Verification file not found: $VERIFY_FILE" >&2
    exit 1
fi

exec_args=("-T")
if [[ -n "$PASSWORD" ]]; then
    exec_args+=(-e "PGPASSWORD=$PASSWORD")
fi
exec_args+=("$PGSQL_SERVICE" psql -U "$USERNAME" -d "$DATABASE" -v ON_ERROR_STOP=1)

set +e
docker compose exec "${exec_args[@]}" < "$VERIFY_FILE"
exit_code=$?
set -e

echo ""
if [[ $exit_code -eq 0 ]]; then
    echo "============================================================"
    echo "  All required schema checks PASSED."
    echo "  WARNs are non-critical and documented in README.md."
    echo "============================================================"
else
    echo "============================================================"
    echo "  Schema verification FAILED. See FAIL rows above." >&2
    echo "  Apply the schema first: bash database/sql/run-schema.sh" >&2
    echo "============================================================"
    exit 3
fi
