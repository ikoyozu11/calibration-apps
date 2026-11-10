Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

Write-Host 'One-shot Gmail SMTP self-test'
Write-Host 'From: instrumentsdesk.vt@gmail.com'
Write-Host 'To:   instrumentsdesk.vt@gmail.com'
Write-Host 'Subject: Instruments Desk - Gmail SMTP Test'
Write-Host 'Scope: exactly one send attempt; no database, scheduler, or calibration data.'
Write-Host ''

$securePassword = Read-Host 'Enter the Gmail App Password' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$plainPassword = $null

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $plainPassword | php (Join-Path $projectRoot 'scripts\gmail-smtp-self-test.php')
    $exitCode = $LASTEXITCODE
}
finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    $plainPassword = $null
    Remove-Variable securePassword -ErrorAction SilentlyContinue
    Remove-Variable passwordPointer -ErrorAction SilentlyContinue
}

if ($exitCode -ne 0) {
    Write-Error 'SMTP self-test did not complete successfully. No automatic retry was performed.'
}

Write-Host ''
Write-Host 'SMTP acceptance is not proof of Inbox delivery. Verify Gmail Inbox or Sent manually.'
