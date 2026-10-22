# Run Instruments Desk against calibration_staging_db without changing .env.
# Staging DB overrides are applied only to child PHP processes. They are not
# assigned on this PowerShell session, so a later `php artisan test` in the
# same window cannot inherit them from this script.
#
# Usage from the project root:
#   powershell -File scripts\serve-staging.ps1
#   powershell -File scripts\serve-staging.ps1 -Port 8001
#
# Do not set DB_CONNECTION / DB_DATABASE / DB_URL in this shell. If they are
# already set, clear them before running tests:
#   Remove-Item Env:DB_CONNECTION, Env:DB_DATABASE, Env:DB_URL -ErrorAction SilentlyContinue

param(
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if ($env:DB_CONNECTION -or $env:DB_DATABASE -or $env:DB_URL) {
    Write-Warning @'
This session already has DB_CONNECTION, DB_DATABASE, or DB_URL. This script will
not add those values to the parent session, but the existing values remain after
the server stops and can leak into Pest/PHPUnit. Clear them before running tests:
  Remove-Item Env:DB_CONNECTION, Env:DB_DATABASE, Env:DB_URL -ErrorAction SilentlyContinue
'@
}

$listener = New-Object System.Net.Sockets.TcpClient
try {
    $listener.Connect('127.0.0.1', $Port)
    $listener.Close()
    throw "Port $Port is already in use. Stop the other server or pass -Port."
} catch [System.Net.Sockets.SocketException] {
    # Port is free.
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
    $info.EnvironmentVariables['DB_CONNECTION'] = 'pgsql'
    $info.EnvironmentVariables['DB_DATABASE'] = 'calibration_staging_db'
    $info.EnvironmentVariables['DB_URL'] = ''
    $info.EnvironmentVariables['STAGING_PREFLIGHT_HTTP'] = '1'

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
Write-Host 'Staging DB environment is confined to this PHP child process.'
# php artisan serve does not reliably pass PowerShell env overrides to HTTP workers on this machine.
$server = Invoke-StagingPhp -Arguments "-S 127.0.0.1:$Port -t public"
exit $server
