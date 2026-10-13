param([int]$Port = 8080, [int]$FcgiPort = 9080, [string]$Caddy = 'caddy', [string]$PhpCgi = 'php-cgi')
$projectPhp=Join-Path (Split-Path $PSScriptRoot -Parent) 'tools/php'
if (Test-Path -LiteralPath (Join-Path $projectPhp 'php.exe')) { $env:PATH=$projectPhp+';'+$env:PATH }
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Split-Path $PSScriptRoot -Parent)
& "$PSScriptRoot/Check-Dependencies.ps1"
if ($Caddy -eq 'caddy' -and (Test-Path -LiteralPath './tools/caddy/caddy.exe')) { $Caddy = (Resolve-Path -LiteralPath './tools/caddy/caddy.exe').Path }
$env:APP_ENV = 'prod'
$env:APP_DEBUG = '0'
& php bin/console app:serve --port=$Port --fcgi-port=$FcgiPort --caddy=$Caddy --php-cgi=$PhpCgi
exit $LASTEXITCODE
