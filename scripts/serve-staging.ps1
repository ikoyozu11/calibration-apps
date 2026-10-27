# Run Instruments Desk against calibration_staging_db without changing .env.
# Staging runtime overrides are applied only to child PHP processes. They are not
# assigned on this PowerShell session, so a later `php artisan test` in the
# same window cannot inherit them from this script.
#
# Usage from the project root:
#   powershell -File scripts\serve-staging.ps1
#   powershell -File scripts\serve-staging.ps1 -Port 8001
#
# Do not set DB_CONNECTION / DB_DATABASE / DB_URL / DATABASE_URL in this shell.
# If they are already set, clear them before running tests:
#   Remove-Item Env:DB_CONNECTION, Env:DB_DATABASE, Env:DB_URL, Env:DATABASE_URL -ErrorAction SilentlyContinue
#
# Also clear SESSION_DRIVER / CACHE_STORE / QUEUE_CONNECTION / STAGING_* if set
# in the parent shell before Pest:
#   Remove-Item Env:SESSION_DRIVER, Env:CACHE_STORE, Env:QUEUE_CONNECTION, Env:STAGING_PREFLIGHT_HTTP, Env:STAGING_RUNTIME_ISOLATION -ErrorAction SilentlyContinue

param(
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if ($env:DB_CONNECTION -or $env:DB_DATABASE -or $env:DB_URL -or $env:DATABASE_URL) {
    Write-Warning @'
This session already has DB_CONNECTION, DB_DATABASE, DB_URL, or DATABASE_URL. This script will
not add those values to the parent session, but the existing values remain after
the server stops and can leak into Pest/PHPUnit. Clear them before running tests:
  Remove-Item Env:DB_CONNECTION, Env:DB_DATABASE, Env:DB_URL, Env:DATABASE_URL -ErrorAction SilentlyContinue
'@
}

$configCache = Join-Path (Get-Location) 'bootstrap\cache\config.php'
if (Test-Path -LiteralPath $configCache) {
    Write-Error @'
Refusing to launch: bootstrap/cache/config.php exists.
Staging runtime requires uncached config so child-process env overrides apply.
Do not run config:cache. Clear only with separate authorization (not this script).
'@
    exit 1
}

$sessionDir = Join-Path (Get-Location) 'storage\framework\sessions-staging'
$cacheDir = Join-Path (Get-Location) 'storage\framework\cache\staging-data'
New-Item -ItemType Directory -Force -Path $sessionDir | Out-Null
New-Item -ItemType Directory -Force -Path $cacheDir | Out-Null

$listener = New-Object System.Net.Sockets.TcpClient
try {
    $listener.Connect('127.0.0.1', $Port)
    $listener.Close()
    throw "Port $Port is already in use. Stop the other server or pass -Port."
} catch [System.Net.Sockets.SocketException] {
    # Port is free.
}

function Set-StagingChildEnvironment {
    param(
        [Parameter(Mandatory)]
        [System.Diagnostics.ProcessStartInfo] $Info,

        [Parameter(Mandatory)]
        [string] $SessionDirectory,

        [Parameter(Mandatory)]
        [string] $CacheDirectory
    )

    $Info.EnvironmentVariables['DB_CONNECTION'] = 'pgsql'
    $Info.EnvironmentVariables['DB_DATABASE'] = 'calibration_staging_db'
    $Info.EnvironmentVariables['DB_HOST'] = '127.0.0.1'
    $Info.EnvironmentVariables['DB_PORT'] = '5432'
    $Info.EnvironmentVariables['DB_URL'] = ''
    $Info.EnvironmentVariables['DATABASE_URL'] = ''
    $Info.EnvironmentVariables['SESSION_DRIVER'] = 'file'
    $Info.EnvironmentVariables['SESSION_FILES'] = $SessionDirectory
    $Info.EnvironmentVariables['CACHE_STORE'] = 'file'
    $Info.EnvironmentVariables['CACHE_FILE_PATH'] = $CacheDirectory
    $Info.EnvironmentVariables['CACHE_FILE_LOCK_PATH'] = $CacheDirectory
    $Info.EnvironmentVariables['QUEUE_CONNECTION'] = 'sync'
    $Info.EnvironmentVariables['STAGING_PREFLIGHT_HTTP'] = '1'
    $Info.EnvironmentVariables['STAGING_RUNTIME_ISOLATION'] = '1'
}

function Invoke-StagingPhp {
    param(
        [Parameter(Mandatory)]
        [string] $Arguments
    )

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = (Get-Command php).Source
    $info.Arguments = $Arguments
    $info.UseShellExecute = $false
    $info.WorkingDirectory = (Get-Location).Path
    Set-StagingChildEnvironment -Info $info -SessionDirectory $sessionDir -CacheDirectory $cacheDir

    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $info
    [void] $process.Start()
    $process.WaitForExit()

    return $process.ExitCode
}

$preflight = Invoke-StagingPhp -Arguments 'artisan staging:preflight'
if ($preflight -ne 0) {
    exit $preflight
}

Write-Host "Starting http://127.0.0.1:$Port against calibration_staging_db"
Write-Host 'Staging DB / session / cache / queue isolation is confined to this PHP child process.'
Write-Host "Sessions: $sessionDir"
Write-Host "Cache: $cacheDir"
# php artisan serve does not reliably pass PowerShell env overrides to HTTP workers on this machine.
$server = Invoke-StagingPhp -Arguments "-S 127.0.0.1:$Port -t public"
exit $server
