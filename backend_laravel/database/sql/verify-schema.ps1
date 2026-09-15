# =============================================================================
# verify-schema.ps1
# Commerce Single Vendor -- PostgreSQL Schema Verification (Docker STDIN)
# =============================================================================

param(
    [string]$PgsqlService = "pgsql",
    [string]$Database     = "ecommerce",
    [string]$Username     = "sail",
    [string]$Password     = "",
    [string]$ProjectDir   = ""
)

$ErrorActionPreference = "Stop"

if (-not $ProjectDir) {
    $ProjectDir = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
}

$VerifyFile = Join-Path $PSScriptRoot "verify-schema.sql"


$envPass = if ($Password) { $Password } elseif ($env:PGPASSWORD) { $env:PGPASSWORD } else { "" }

Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  Commerce Single Vendor -- Schema Verification"               -ForegroundColor Cyan
Write-Host "  Docker STDIN Pipe: no host psql required"                    -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  Service:  $PgsqlService"
Write-Host "  Database: $Database"
Write-Host "  User:     $Username"
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

# --- Step 1: Docker check -----------------------------------------------------
Write-Host "[1/3] Checking Docker availability..." -NoNewline
docker info > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Host "ERROR: Docker is not running." -ForegroundColor Red
    exit 1
}
Write-Host " OK" -ForegroundColor Green

# --- Step 2: Container check --------------------------------------------------
Write-Host "[2/3] Checking '$PgsqlService' container..." -NoNewline
$psOut = (docker compose ps $PgsqlService 2>&1) | Out-String
if ($psOut -notmatch "healthy|Up") {
    Write-Host " FAILED" -ForegroundColor Red
    Write-Host "ERROR: '$PgsqlService' is not running. Run: docker compose up -d" -ForegroundColor Red
    exit 1
}
Write-Host " healthy" -ForegroundColor Green

# --- Step 3: Run verification --------------------------------------------------
Write-Host "[3/3] Running schema verification..."
Write-Host ""

if (-not (Test-Path $VerifyFile)) {
    Write-Host "ERROR: Verification script not found at: $VerifyFile" -ForegroundColor Red
    exit 1
}

# NOTE (UTF-8 safety): the SQL file is staged into the container with
# `docker cp` and executed with `psql -f` so no Windows-shell STDIN pipe
# can re-encode non-ASCII bytes (see run-schema.ps1 for details).
$PgsqlContainerId = (docker compose ps -q $PgsqlService 2>&1 | Select-Object -First 1).ToString().Trim()
if (-not $PgsqlContainerId) {
    Write-Host "ERROR: Could not resolve container ID for service '$PgsqlService'." -ForegroundColor Red
    exit 1
}

$containerPath = "/tmp/verify-schema.sql"
docker cp "$VerifyFile" "${PgsqlContainerId}:$containerPath"
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Failed to stage verification script into container." -ForegroundColor Red
    exit 1
}

if ($envPass) {
    docker compose exec -T -e "PGPASSWORD=$envPass" -e "PGCLIENTENCODING=UTF8" $PgsqlService psql -U $Username -d $Database -v ON_ERROR_STOP=1 -f $containerPath
} else {
    docker compose exec -T -e "PGCLIENTENCODING=UTF8" $PgsqlService psql -U $Username -d $Database -v ON_ERROR_STOP=1 -f $containerPath
}

$exitCode = $LASTEXITCODE
docker compose exec -T $PgsqlService rm -f $containerPath 2>&1 | Out-Null

Write-Host ""
if ($exitCode -eq 0) {
    Write-Host "============================================================" -ForegroundColor Green
    Write-Host "  All required schema checks PASSED."                         -ForegroundColor Green
    Write-Host "  WARNs are non-critical and documented in README.md."        -ForegroundColor Green
    Write-Host "============================================================" -ForegroundColor Green
} else {
    Write-Host "============================================================" -ForegroundColor Red
    Write-Host "  Schema verification FAILED. See FAIL rows above."           -ForegroundColor Red
    Write-Host "  Apply the schema first: .\database\sql\run-schema.ps1"     -ForegroundColor Yellow
    Write-Host "============================================================" -ForegroundColor Red
    exit 3
}
