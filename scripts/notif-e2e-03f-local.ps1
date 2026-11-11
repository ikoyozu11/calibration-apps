$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpRunner = Join-Path $projectRoot 'scripts/notif-e2e-03f-runner.php'
$markerPath = Join-Path $projectRoot 'storage/app/notif-e2e-03f-live.marker'
$configCachePath = Join-Path $projectRoot 'bootstrap/cache/config.php'
$allowedRecipient = 'service.malang@vision-teknik.com'
$environmentNames = @(
    'DB_CONNECTION',
    'DB_DATABASE',
    'DB_URL',
    'MAIL_MAILER',
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_SCHEME',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_FROM_ADDRESS',
    'MAIL_FROM_NAME',
    'CALIBRATION_DUE_NOTIFICATIONS_ENABLED'
)
$previousEnvironment = @{}

if (-not (Test-Path -LiteralPath $phpRunner -PathType Leaf)) {
    throw "Runner entrypoint was not found: $phpRunner"
}

if (Test-Path -LiteralPath $configCachePath -PathType Leaf) {
    throw 'Persistent Laravel config cache exists. Refusing to continue without changing or clearing it.'
}

if (Test-Path -LiteralPath $markerPath -PathType Leaf) {
    throw "The canonical one-time marker already exists. Refusing to send: $markerPath"
}

$senderAddress = Read-Host 'Gmail sender address'
$securePassword = Read-Host 'Google App Password (hidden)' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)

try {
    $senderPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
}

try {
    foreach ($name in $environmentNames) {
        $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }

    [Environment]::SetEnvironmentVariable('DB_CONNECTION', 'sqlite', 'Process')
    [Environment]::SetEnvironmentVariable('DB_DATABASE', ':memory:', 'Process')
    [Environment]::SetEnvironmentVariable('DB_URL', '', 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_MAILER', 'smtp', 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_HOST', 'smtp.gmail.com', 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_PORT', '587', 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_SCHEME', 'smtp', 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_USERNAME', $senderAddress, 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_PASSWORD', $senderPassword, 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_FROM_ADDRESS', $senderAddress, 'Process')
    [Environment]::SetEnvironmentVariable('MAIL_FROM_NAME', 'Instruments Desk', 'Process')
    [Environment]::SetEnvironmentVariable('CALIBRATION_DUE_NOTIFICATIONS_ENABLED', 'false', 'Process')

    Push-Location $projectRoot
    try {
        $preflightJson = & php $phpRunner preflight $markerPath
        if ($LASTEXITCODE -ne 0) {
            throw 'Guarded preflight failed. No SMTP attempt was made.'
        }

        $preflight = ($preflightJson -join "`n") | ConvertFrom-Json
        $preflightValid = ($preflight.recipient -eq $allowedRecipient) -and ([int] $preflight.reminder_count -eq 1) -and ([int] $preflight.expected_email_count -eq 1) -and ($preflight.milestone -eq 'due_today')
        if (-not $preflightValid) {
            throw 'Preflight result did not satisfy the one-recipient, one-due_today reminder gate.'
        }

        Write-Host ''
        Write-Host 'FINAL SEND CONFIRMATION' -ForegroundColor Yellow
        Write-Host "Sender: $senderAddress"
        Write-Host "Recipient: $allowedRecipient"
        Write-Host 'SMTP: smtp.gmail.com:587 (STARTTLS)'
        Write-Host 'Database: SQLite :memory:'
        Write-Host 'Maximum attempts: ONE'
        Write-Host ''
        $confirmation = Read-Host 'Type SEND to authorize the single live SMTP attempt'

        if ($confirmation -cne 'SEND') {
            Write-Host 'Cancelled. No SMTP attempt was made.' -ForegroundColor Cyan
            exit 0
        }

        if (Test-Path -LiteralPath $markerPath -PathType Leaf) {
            throw 'The canonical one-time marker appeared before send. Refusing to continue.'
        }

        $sendJson = & php $phpRunner send $markerPath
        if ($LASTEXITCODE -ne 0) {
            throw 'Guarded send failed. Do not retry; inspect the result as failed or delivery-unknown.'
        }

        Write-Host (($sendJson -join "`n"))
        Write-Host 'SMTP attempt completed. Verify mailbox receipt separately.' -ForegroundColor Green
    } finally {
        Pop-Location
    }
} finally {
    foreach ($name in $environmentNames) {
        $oldValue = $previousEnvironment[$name]
        [Environment]::SetEnvironmentVariable($name, $oldValue, 'Process')
    }

    $senderPassword = $null
    $securePassword = $null
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
