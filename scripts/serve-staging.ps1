# Run Instruments Desk against calibration_staging_db without changing .env.
# Usage from the project root:
#   powershell -File scripts\serve-staging.ps1
#   powershell -File scripts\serve-staging.ps1 -Port 8001

param(
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$env:DB_CONNECTION = 'pgsql'
$env:DB_DATABASE = 'calibration_staging_db'
$env:DB_URL = ''
$env:STAGING_PREFLIGHT_HTTP = '1'

$listener = New-Object System.Net.Sockets.TcpClient
try {
    $listener.Connect('127.0.0.1', $Port)
    $listener.Close()
    throw "Port $Port is already in use. Stop the other server or pass -Port."
} catch [System.Net.Sockets.SocketException] {
    # Port is free.
}

php artisan staging:preflight
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host "Starting http://127.0.0.1:$Port against calibration_staging_db"
# php artisan serve does not reliably pass PowerShell env overrides to HTTP workers on this machine.
php -S 127.0.0.1:$Port -t public
