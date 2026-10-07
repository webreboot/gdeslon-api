#Requires -Version 5.1
<#
.SYNOPSIS
  Короткий вывод make remote-phpunit для циклов red/green: номера упавших тестов, причины и итог.
  Использование: powershell -File scripts/phpunit-summary.ps1 <Filter> [php81]
#>
param(
    [Parameter(Mandatory = $true)][string]$Filter,
    [string]$Php = ''
)
Set-Location (Split-Path -Parent $PSScriptRoot)
$ErrorActionPreference = 'Continue'
make remote-phpunit "FILTER=$Filter" "PHP=$Php" 2>&1 |
    ForEach-Object { "$_" -replace '\x1b\[[0-9;]*m', '' } |
    Select-String -Pattern '^\d+\) |^(Error|Failed asserting|TypeError|Exception)|Tests: |^OK |No tests executed|(PHP )?(Fatal|Parse) error' |
    ForEach-Object { $_.Line }
exit $LASTEXITCODE
