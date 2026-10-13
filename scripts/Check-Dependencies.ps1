$projectPhp=Join-Path (Split-Path $PSScriptRoot -Parent) 'tools/php'
if (Test-Path -LiteralPath (Join-Path $projectPhp 'php.exe')) { $env:PATH=$projectPhp+';'+$env:PATH }
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'Install PHP 8.2 or newer and add php.exe to PATH.' }
& php "$root/bin/check-dependencies.php"
if ($LASTEXITCODE -ne 0) { throw 'PHP dependency check failed.' }
if (-not (Test-Path -LiteralPath "$root/vendor/autoload.php")) { throw 'Run composer install in the project folder first.' }
if (Get-Command ffprobe -ErrorAction SilentlyContinue) { & ffprobe -version | Select-Object -First 1 } else { Write-Warning 'FFprobe is not on PATH. Phase 1 works without it; media inspection in phase 2 requires it.' }
Write-Host 'Foundation dependencies are ready.'
