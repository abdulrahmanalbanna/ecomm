# =============================================================================
# run-schema.ps1
# Applies the PostgreSQL baseline schema in dependency order via Docker STDIN.
# =============================================================================

param(
    [string]$PgsqlService = "pgsql",
    [string]$Database     = "ecommerce",
    [string]$Username     = "sail",
    [string]$Password     = "",
    [switch]$Force        = $false,
    [string]$ProjectDir   = ""
)

$ErrorActionPreference = "Stop"

if (-not $ProjectDir) {
    $ProjectDir = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
}

$SqlDir = $PSScriptRoot


Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  Commerce Single Vendor -- PostgreSQL Schema Installer"       -ForegroundColor Cyan
Write-Host "  Docker STDIN Pipe: no host psql or mounts required"          -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  Service:  $PgsqlService"
Write-Host "  Database: $Database"
Write-Host "  User:     $Username"
Write-Host "  Project:  $ProjectDir"
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

$envPass = if ($Password) { $Password } elseif ($env:PGPASSWORD) { $env:PGPASSWORD } else { "" }

# --- Step 1: Verify Docker is available ---------------------------------------
Write-Host "[1/6] Checking Docker availability..." -NoNewline
docker info > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Host "ERROR: Docker is not running or not accessible." -ForegroundColor Red
    exit 1
}
Write-Host " OK" -ForegroundColor Green

# --- Step 2: Verify container health -----------------------------------------
Write-Host "[2/6] Checking '$PgsqlService' container health..." -NoNewline
$psOut = (docker compose ps $PgsqlService 2>&1) | Out-String
if ($psOut -notmatch "healthy|Up") {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Host "ERROR: '$PgsqlService' container is not running or not healthy." -ForegroundColor Red
    exit 1
}
Write-Host " healthy" -ForegroundColor Green

# --- Step 3: Verify PostgreSQL connectivity ------------------------------------
Write-Host "[3/6] Verifying PostgreSQL connectivity..." -NoNewline
if ($envPass) {
    $pgVer = docker compose exec -T -e "PGPASSWORD=$envPass" $PgsqlService psql -U $Username -d $Database -Atc "SELECT version();" 2>&1
} else {
    $pgVer = docker compose exec -T $PgsqlService psql -U $Username -d $Database -Atc "SELECT version();" 2>&1
}

if ($LASTEXITCODE -ne 0) {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Host "ERROR: Cannot connect to PostgreSQL." -ForegroundColor Red
    Write-Host "$pgVer" -ForegroundColor Yellow
    exit 1
}
Write-Host " OK" -ForegroundColor Green
$verStr = ($pgVer | Select-Object -First 1).ToString().Trim()
Write-Host "         PostgreSQL: $verStr"

# --- Step 4: Safety check ------------------------------------------------------
Write-Host "[4/6] Checking database state..." -NoNewline
if ($envPass) {
    $countOut = docker compose exec -T -e "PGPASSWORD=$envPass" $PgsqlService psql -U $Username -d $Database -Atc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';" 2>&1
} else {
    $countOut = docker compose exec -T $PgsqlService psql -U $Username -d $Database -Atc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';" 2>&1
}

$tblCountStr = ($countOut | Select-Object -First 1).ToString().Trim()
$tableCount = 0
if ($tblCountStr -match '^\d+$') {
    $tableCount = [int]$tblCountStr
}

if ($tableCount -gt 0 -and -not $Force) {
    Write-Host " STOPPED" -ForegroundColor Yellow
    Write-Host "WARNING: Database '$Database' contains $tableCount table(s). Use -Force to re-apply." -ForegroundColor Yellow
    exit 0
}

if ($tableCount -gt 0) {
    Write-Host " WARNING: $tableCount existing tables -- applying with -Force" -ForegroundColor Yellow
} else {
    Write-Host " empty (fresh)" -ForegroundColor Green
}

# --- SQL file execution order ---------------------------------------------------
$SqlFiles = @(
    "001_extensions_enums.sql",
    "002_identity_auth.sql",
    "003_customers_addresses.sql",
    "004_categories.sql",
    "005_products_attributes_variants.sql",
    "006_inventory.sql",
    "007_cart.sql",
    "008_orders.sql",
    "009_shipping.sql",
    "010_payment_gateways.sql",
    "011_payments.sql",
    "012_installments_refunds.sql",
    "013_promotions.sql",
    "014_reviews.sql",
    "015_notifications.sql",
    "016_audit_logs.sql",
    "017_indexes.sql",
    "018_functions_triggers.sql",
    "019_materialized_views.sql",
    "021_seed_data.sql",
    "022_catalog_seed.sql"
)

# --- Step 5: Apply SQL files ---------------------------------------------------
# NOTE (UTF-8 safety): SQL files are piped to psql as RAW BYTES via
# `docker cp` + `psql -f` inside the container. NEVER use
# `Get-Content ... | docker compose exec ... psql` here: PowerShell 5.1
# decodes the file with the system ANSI code page and re-encodes piped
# strings with $OutputEncoding (often US-ASCII), which replaces every
# non-ASCII byte (Arabic, —, •, ©, …) with '?'.
Write-Host "[5/6] Applying schema files ($($SqlFiles.Count) files)..."
Write-Host ""

# Resolve the running container ID once (docker cp needs it).
$PgsqlContainerId = (docker compose ps -q $PgsqlService 2>&1 | Select-Object -First 1).ToString().Trim()
if (-not $PgsqlContainerId) {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Host "ERROR: Could not resolve container ID for service '$PgsqlService'." -ForegroundColor Red
    exit 1
}

$applied = 0
foreach ($sqlFile in $SqlFiles) {
    $filePath = Join-Path $SqlDir $sqlFile
    if (-not (Test-Path $filePath)) {
        Write-Host " FAILED" -ForegroundColor Red
        Write-Host "ERROR: File not found: $filePath" -ForegroundColor Red
        exit 1
    }

    Write-Host "  Applying: $sqlFile ..." -NoNewline

    # Save current Preference and allow stderr output without throwing NativeCommandError
    $oldEap = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    $containerPath = "/tmp/schema_$sqlFile"
    $fileOut = $null
    $exitStatus = 1

    docker cp "$filePath" "${PgsqlContainerId}:$containerPath" 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        if ($envPass) {
            $fileOut = docker compose exec -T -e "PGPASSWORD=$envPass" -e "PGCLIENTENCODING=UTF8" $PgsqlService psql -U $Username -d $Database -v ON_ERROR_STOP=1 -v client_min_messages=warning --quiet -f $containerPath 2>&1
        } else {
            $fileOut = docker compose exec -T -e "PGCLIENTENCODING=UTF8" $PgsqlService psql -U $Username -d $Database -v ON_ERROR_STOP=1 -v client_min_messages=warning --quiet -f $containerPath 2>&1
        }
        $exitStatus = $LASTEXITCODE
        # Best-effort cleanup of the staged file inside the container.
        docker compose exec -T $PgsqlService rm -f $containerPath 2>&1 | Out-Null
    } else {
        $fileOut = "docker cp failed for $sqlFile"
    }

    $ErrorActionPreference = $oldEap

    if ($exitStatus -ne 0) {
        Write-Host " FAILED" -ForegroundColor Red
        Write-Host ""
        Write-Host "ERROR: Schema application failed at $sqlFile" -ForegroundColor Red
        Write-Host "$fileOut" -ForegroundColor Yellow
        exit 1
    }

    $applied++
    Write-Host " done" -ForegroundColor Green
}

# --- Step 6: Summary -----------------------------------------------------------
Write-Host ""
Write-Host "[6/6] Verifying object counts..."
if ($envPass) {
    $finalOut = docker compose exec -T -e "PGPASSWORD=$envPass" $PgsqlService psql -U $Username -d $Database -Atc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>&1
} else {
    $finalOut = docker compose exec -T $PgsqlService psql -U $Username -d $Database -Atc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>&1
}
$finalStr = ($finalOut | Select-Object -First 1).ToString().Trim()
Write-Host "      Tables in public schema: $finalStr" -ForegroundColor Cyan

Write-Host ""
Write-Host "============================================================" -ForegroundColor Green
Write-Host "  Schema applied successfully ($applied files)."               -ForegroundColor Green
Write-Host "  Next steps:"                                                  -ForegroundColor Green
Write-Host "    1. Run: .\database\sql\verify-schema.ps1"                   -ForegroundColor Green
Write-Host "    2. Run: docker compose exec laravel.test php artisan about" -ForegroundColor Green
Write-Host "============================================================" -ForegroundColor Green
Write-Host ""
