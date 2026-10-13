# Phase 1 runtime operations

The normal launcher runs **Caddy 2.11.7 → PHP FastCGI**, alongside two Symfony Messenger consumers. The supervisor is a PHP CLI command shared by Windows and Linux. This is a normal HTTP server, not PHP's development server. It binds only loopback; LAN enablement remains phase 6.

## Install and launch

Install a maintained PHP 8.2+ patch release, matching CLI/CGI binaries, Composer, and Caddy 2.11.7. Required extensions: ctype, iconv, PDO SQLite, mbstring, session; testing also requires DOM/XML/XMLWriter. `php bin/check-dependencies.php` verifies the baseline. For Debian-family Linux, PHP packages include `php-cli php-cgi php-sqlite3 php-mbstring php-xml php-curl`. Package versions must match; the supervisor compares the running web and CLI PHP versions/extensions.

Get Caddy from its [official installation page](https://caddyserver.com/docs/install) or [2.11.7 release](https://github.com/caddyserver/caddy/releases/tag/v2.11.7). Verify release downloads against the release's SHA512 checksum file. Add Caddy to PATH or supply its executable path. The Windows development machine has the verified binary in `tools/caddy/` (ignored from source). Caddy's PHP integration follows the [official FastCGI documentation](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi).

Windows:

```powershell
composer install
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Start.ps1 -Port 8080 -FcgiPort 9080
# In a second terminal, same directory and APP_DATA_DIR:
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Stop.ps1
```

Linux:

```bash
composer install
bash scripts/start.sh --port=8080 --fcgi-port=9080
# In a second terminal, same directory and APP_DATA_DIR:
bash scripts/stop.sh
```

Windows executable overrides: `-Caddy C:/tools/caddy.exe -PhpCgi C:/php/php-cgi.exe`. Linux: `--caddy=/usr/local/bin/caddy --php-cgi=/usr/bin/php-cgi`. Paths with spaces must be quoted. All application subprocess arguments use arrays rather than concatenated shell commands.

Open http://127.0.0.1:8080 and create the owner account. No default password is shipped. No media location is automatically chosen. The default FastCGI port is 9080 and is also bound exclusively to loopback. No firewall/router changes are made. Keep the supervising terminal open; use the stop script to stop its processes. OS service/autostart integration remains optional future work.

## Data and existing development setup

Normal launches use `%LOCALAPPDATA%/MovieLibrary` on Windows, or `$XDG_DATA_HOME/movie-library` (fallback `~/.local/share/movie-library`) on Linux. `APP_DATA_DIR` overrides this. SQLite must be on local disk. The normal launcher never silently imports the development database.

To retain the owner/locations already configured in the development UI, stop the development server first and explicitly select its data directory for both normal start and stop:

```powershell
powershell -File scripts/Stop-Dev.ps1
$env:APP_DATA_DIR = 'D:\MovieLibrary\var\dev'
powershell -File scripts/Start.ps1
```

On Linux use `export APP_DATA_DIR=/absolute/project/path/var/dev`. Production and development cache directories remain separate. Migrations are additive and preserve owner credentials and locations. Automated tests always use separate data; runtime smoke tests use randomly named `var/runtime-smoke-*` directories containing synthetic records.

Data includes the SQLite database/WAL, generated secret, sessions, cache, logs, Caddy storage and supervisor ownership file. Serve only `public/`.

Use `php bin/console app:backup` for a consistent catalog snapshot under `APP_DATA_DIR/backups/`. To restore, stop the application and workers, then run `php bin/console app:restore "BACKUP_DIRECTORY"` from the local owner terminal. Validation, a pre-restore backup, and atomic replacement are performed automatically; credentials are preserved and active jobs are marked interrupted.

## Workers and troubleshooting

Settings shows filesystem/metadata heartbeat timestamps and a stale/stopped warning after 15 seconds without a heartbeat. Workers process durable Messenger queues in SQLite. Startup sends an idempotent diagnostic message to each; **these are infrastructure checks, not availability scans**. Discovery/availability handlers are phase 2/3, and TMDB handlers are phase 4.

The supervisor holds an exclusive lock per data directory, and each worker holds its own queue lock. Stop requests carry the active instance token; no arbitrary PID from a previous launch is killed. A child failure fails the whole supervised instance and stops its other children. A new launch can recover stale runtime metadata after an abnormal stop; if orphan workers remain, their locks prevent overlapping execution and startup reports the conflict.

Logs are in `APP_DATA_DIR/logs/`: `http.log`, `php-fastcgi.log`, `filesystem.log`, `metadata.log`. Logs over 5 MiB rotate to `.previous` at startup; live rotation/redaction expansion is release-phase work. No metadata credential handling exists yet. For CLI diagnostics set `APP_ENV=prod` and the same `APP_DATA_DIR` first:

```text
php bin/console doctrine:migrations:status
php bin/console messenger:stats
php bin/console messenger:failed:show
```

Manual consumers, useful for development, run `php bin/console messenger:consume filesystem` and `php bin/console messenger:consume metadata` in separate terminals. Do not run them alongside the supervised consumers. The failure transport retains exhausted messages. Retries are bounded; redelivery timeouts are 24 hours for filesystem and 1 hour for metadata. Before implementing long scans, use bounded handlers and add keepalive/ownership-aware recovery. A timeout value alone is not a long-scan safety guarantee.

## Repeat validation

```text
composer test
composer check
composer validate --strict
php tests/runtime-smoke.php /path/to/caddy /path/to/php-cgi
```

The runtime smoke test starts a separate local instance, tests restart persistence, deliberately terminates its metadata worker, then verifies failure cleanup. Do not run it in a process-restricted sandbox that cannot terminate its own child trees. It never opens owner data or media.

An optional isolated Linux verification harness is included. Docker is used only for testing from Windows, not required by the application:

```text
docker build -t movie-library-phase1-test -f tests/linux/Dockerfile .
docker run --rm --mount type=bind,source=<absolute-project-path>,target=/source,readonly movie-library-phase1-test bash /source/tests/linux/run.sh
```

The harness copies source into the container, installs from `composer.lock`, tests the schema and both queues, validates production start/stop/failure and development startup, and removes the container on completion. Source is mounted read-only and real media is never mounted.
