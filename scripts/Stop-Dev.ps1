$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$data = if ($env:APP_DATA_DIR) { $env:APP_DATA_DIR } else { Join-Path $root 'var/dev' }
$pidFile = Join-Path $data 'dev-server.json'
if (-not (Test-Path -LiteralPath $pidFile)) { Write-Host 'No recorded Movie Library development server.'; exit 0 }
$record = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
$process = Get-Process -Id $record.ProcessId -ErrorAction SilentlyContinue
if ($process) {
    if ($process.StartTime.ToUniversalTime().ToString('o') -ne $record.StartTime -or $process.Path -ne $record.Executable) { throw 'Process identity does not match. Refusing to stop an unrelated process.' }
    Stop-Process -Id $process.Id
}
Remove-Item -LiteralPath $pidFile
Write-Host 'Movie Library development server stopped.'
