#!/usr/bin/env bash
set -euo pipefail
project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$project_root"
port="${1:-8000}"
if [[ ! "$port" =~ ^[0-9]{4,5}$ ]] || (( 10#$port < 1024 || 10#$port > 65535 )); then
    echo 'Choose a port from 1024 to 65535.' >&2
    exit 1
fi
command -v php >/dev/null || { echo 'Install PHP 8.2+ and add it to PATH.' >&2; exit 1; }
php bin/check-dependencies.php
[[ -f vendor/autoload.php ]] || { echo 'Run composer install first.' >&2; exit 1; }
command -v ffprobe >/dev/null || echo 'Warning: FFprobe is missing; phase 2 inspection will require it.' >&2
export APP_ENV=dev APP_DEBUG=0
export APP_DATA_DIR="${APP_DATA_DIR:-$project_root/var/dev}"
mkdir -p -- "$APP_DATA_DIR"
export APP_DATA_DIR="$(cd -- "$APP_DATA_DIR" && pwd)"
php bin/console doctrine:migrations:migrate --no-interaction
echo "Movie Library development server: http://127.0.0.1:$port"
echo "Data: $APP_DATA_DIR"
echo 'Runs in this terminal; press Ctrl+C to stop. No background workers in phase 1.'
# Foreground exec: shell owns no detached process; bind failures exit nonzero.
exec php -S "127.0.0.1:$port" -t public public/router.php
