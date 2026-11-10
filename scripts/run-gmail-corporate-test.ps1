Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

Write-Host 'Corporate SMTP delivery test'
Write-Host 'From: instrumentsdesk.vt@gmail.com'
Write-Host 'To A: service.malang@vision-teknik.com'
Write-Host 'To B: Indra_Tri_Sutanto@bat.com'
Write-Host 'Subject: Instruments Desk - Email Delivery Test'
Write-Host 'Scope: two separate messages; maximum two send attempts; no CC/BCC; no retries.'
Write-Host ''

$securePassword = Read-Host 'Enter the Gmail App Password' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$plainPassword = $null

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $plainPassword | php (Join-Path $projectRoot 'scripts\gmail-corporate-test.php')
    $exitCode = $LASTEXITCODE
}
finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    $plainPassword = $null
    Remove-Variable securePassword -ErrorAction SilentlyContinue
    Remove-Variable passwordPointer -ErrorAction SilentlyContinue
}

if ($exitCode -ne 0) {
    Write-Error 'Corporate SMTP test did not complete successfully. No automatic retry was performed.'
}

Write-Host ''
Write-Host 'SMTP acceptance is not proof of Inbox delivery. Ask both mailbox owners to confirm receipt.'
