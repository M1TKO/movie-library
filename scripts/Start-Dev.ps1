param([ValidateRange(1024,65535)][int]$Port = 8000)
$projectPhp=Join-Path (Split-Path $PSScriptRoot -Parent) 'tools/php'
if (Test-Path -LiteralPath (Join-Path $projectPhp 'php.exe')) { $env:PATH=$projectPhp+';'+$env:PATH }
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $root
& "$PSScriptRoot/Check-Dependencies.ps1"
$env:APP_ENV = 'dev'
$env:APP_DEBUG = '0'
if (-not $env:APP_DATA_DIR) { $env:APP_DATA_DIR = Join-Path $root 'var/dev' }
New-Item -ItemType Directory -Force -Path $env:APP_DATA_DIR | Out-Null
$data = (Resolve-Path -LiteralPath $env:APP_DATA_DIR).Path
$pidFile = Join-Path $data 'dev-server.json'
if (Test-Path -LiteralPath $pidFile) {
    $record = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
    $running = Get-Process -Id $record.ProcessId -ErrorAction SilentlyContinue
    if ($running -and $running.StartTime.ToUniversalTime().ToString('o') -eq $record.StartTime) { throw "Movie Library is already running at http://127.0.0.1:$($record.Port)" }
}
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
try { $listener.Start() } finally { $listener.Stop() }
& php bin/console doctrine:migrations:migrate --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed.' }
$php = (Get-Command php).Source
$process = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$Port", '-t', 'public', 'public/router.php') -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput "$data/server-output.log" -RedirectStandardError "$data/server-error.log"
$startTime = $process.StartTime.ToUniversalTime().ToString('o')
@{ ProcessId=$process.Id; StartTime=$startTime; Port=$Port; Executable=$php } | ConvertTo-Json | Set-Content -LiteralPath $pidFile
Start-Sleep -Seconds 1
$process.Refresh()
if ($process.HasExited) { throw "HTTP server stopped. Read $data/server-error.log" }
try {
    $response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/login" -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -ne 200) { throw 'Unexpected HTTP response.' }
} catch {
    Stop-Process -Id $process.Id -ErrorAction SilentlyContinue
    throw "Application health check failed. Read $data/server-error.log. $($_.Exception.Message)"
}
Write-Host "Movie Library development server: http://127.0.0.1:$Port"
Write-Host "Data: $data"
Write-Host 'Phase 1: no scan worker yet. Stop with scripts/Stop-Dev.ps1.'
