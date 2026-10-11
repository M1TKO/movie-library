#!/usr/bin/env bash
set -euo pipefail
# /source is read-only; all dependencies, databases and process tests stay in /app.
cp -a /source/src /source/config /source/templates /source/public /source/migrations /source/tests /source/scripts /source/bin /app/
cp /source/composer.json /source/composer.lock /source/.env /source/phpunit.xml.dist /app/
cd /app
composer install --no-interaction --no-progress
composer test
export APP_DATA_DIR=/app/var/validation
composer check
composer validate --strict
php tests/runtime-smoke.php
# Verify the Linux development launcher separately.
export APP_DATA_DIR=/app/var/development-smoke
bash scripts/start-dev.sh 8093 > /app/dev-server.log 2>&1 &
dev_pid=$!
trap 'kill "$dev_pid" 2>/dev/null || true' EXIT
for attempt in $(seq 1 40); do
    if curl --silent --fail http://127.0.0.1:8093/setup > /app/setup.html; then break; fi
    sleep 1
done
grep -q 'Create owner account' /app/setup.html
kill "$dev_pid"
wait "$dev_pid" || true
trap - EXIT
echo 'Linux development launcher smoke passed.'
