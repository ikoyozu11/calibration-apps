# Run one Laravel scheduler tick against calibration_staging_db.
# This script is intentionally separate from the HTTP staging launcher.
# It never changes the parent PowerShell environment or persistent config.

param(
    [switch]$Once
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$configCache = Join-Path (Get-Location) 'bootstrap\cache\config.php'
if (Test-Path -LiteralPath $configCache) {
    Write-Error 'Refusing to launch staging reconciliation: bootstrap/cache/config.php exists.'
    exit 1
}

$cacheDir = Join-Path (Get-Location) 'storage\framework\cache\staging-reconciliation'
New-Item -ItemType Directory -Force -Path $cacheDir | Out-Null

function Invoke-StagingCommand {
    param(
        [Parameter(Mandatory)]
        [string]$Arguments
    )

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = (Get-Command php).Source
    $info.Arguments = $Arguments
    $info.UseShellExecute = $false
    $info.WorkingDirectory = (Get-Location).Path
    $info.EnvironmentVariables['DB_CONNECTION'] = 'pgsql'
    $info.EnvironmentVariables['DB_DATABASE'] = 'calibration_staging_db'
    $info.EnvironmentVariables['DB_HOST'] = '127.0.0.1'
    $info.EnvironmentVariables['DB_PORT'] = '5432'
    $info.EnvironmentVariables['DB_URL'] = ''
    $info.EnvironmentVariables['DATABASE_URL'] = ''
    $info.EnvironmentVariables['SESSION_DRIVER'] = 'file'
    $info.EnvironmentVariables['CACHE_STORE'] = 'file'
    $info.EnvironmentVariables['CACHE_FILE_PATH'] = $cacheDir
    $info.EnvironmentVariables['CACHE_FILE_LOCK_PATH'] = $cacheDir
    $info.EnvironmentVariables['QUEUE_CONNECTION'] = 'sync'
    $info.EnvironmentVariables['STAGING_RUNTIME_ISOLATION'] = '1'
    $info.EnvironmentVariables['CALIBRATION_NOTIFICATION_RECONCILIATION_STAGING'] = '1'

    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $info
    [void]$process.Start()
    $process.WaitForExit()

    return $process.ExitCode
}

$preflight = Invoke-StagingCommand -Arguments 'artisan staging:preflight'
if ($preflight -ne 0) {
    exit $preflight
}

if ($Once) {
    exit (Invoke-StagingCommand -Arguments 'artisan schedule:run')
}

Write-Host 'Use Windows Task Scheduler to invoke this script once per minute.'
Write-Host 'The Laravel schedule remains daily at 08:00 Asia/Jakarta.'
