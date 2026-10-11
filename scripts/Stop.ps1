$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Split-Path $PSScriptRoot -Parent)
$env:APP_ENV = 'prod'
$env:APP_DEBUG = '0'
& php bin/console app:stop
exit $LASTEXITCODE
