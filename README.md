# Movie Library

A personal movie and TV catalog for **Windows and Linux**, implemented in phases from [the project specification](Movie-Library-Specification.md). Both platforms are required release targets.

**Phase 1 is complete.** Owner setup/login, location configuration, the full catalog/job schema, durable queues, worker health, and supervised Caddy/PHP FastCGI startup are implemented and tested on Windows and Linux. Discovery and catalog browsing, availability handlers, TMDB, and playback remain later-phase features. See [IMPLEMENTATION.md](IMPLEMENTATION.md) and [validation results](docs/VALIDATION.md).

## Normal application startup

Install PHP CLI and matching PHP CGI (8.2+), Composer dependencies, and [Caddy](https://caddyserver.com/docs/install). Caddy **2.11.7** is the tested server version. No Docker or WSL is required to run the app.

Windows, from this directory:

```powershell
composer install
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Start.ps1
```

The Windows launcher uses `tools/caddy/caddy.exe` if present, otherwise Caddy from PATH; `-Caddy` and `-PhpCgi` allow explicit executable paths. A verified project-local Caddy binary was downloaded on the development machine; it is not committed source.

Linux, from this directory:

```bash
composer install
bash scripts/start.sh
```

Open **http://127.0.0.1:8080**. Startup applies migrations and starts Caddy, PHP FastCGI, and separate filesystem/metadata workers. It reports ready only after HTTP validation and real queue acknowledgements. A child process failure stops the remaining owned processes and reports the relevant log. Run in a terminal under the account that will access your media.

Stop from another terminal with `powershell -File scripts/Stop.ps1` on Windows or `bash scripts/stop.sh` on Linux. Use the same `APP_DATA_DIR` for start and stop. See [runtime operations](docs/RUNTIME.md) for data locations, ports, installation and worker diagnosis. LAN remains disabled until the release phase.

## Start locally

In PowerShell:

```powershell
cd D:\MovieLibrary
composer install
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Start-Dev.ps1
```

On Linux, with PHP 8.2+, the required extensions, Composer, and Bash installed:

```bash
cd /path/to/MovieLibrary
composer install
bash scripts/start-dev.sh
```

The Linux development server runs in the foreground; **Ctrl+C** stops it. An optional first argument selects the port: `bash scripts/start-dev.sh 8001`. Logs go to the terminal. No detached process or stop script is needed for this development mode.

Open **http://127.0.0.1:8000**. Create your owner password (at least 12 characters), sign in, then open **Locations** to save absolute paths on the backend host. Windows accepts drive/UNC paths; Linux accepts paths such as `/mnt/media/Movies`, including mounted network storage. Windows identity is case-insensitive; Linux preserves case and meaningful filename characters. Linux paths containing `..` are rejected to avoid incorrectly resolving symlinks. Labels, recursion, content type, and enabled state are stored. Offline paths can be saved; access has not yet been checked. Adding a location never scans it. Only one owner account can be created, and setup is restricted to the host's loopback address.

Stop this application's development server:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\Stop-Dev.ps1
```

Optional port: `Start-Dev.ps1 -Port 8001`. Duplicate launches are rejected. Stop verifies both process creation time and executable before stopping the recorded process. Startup applies migrations, checks port availability, and verifies an HTTP response. Read `var/dev/server-error.log` if startup fails.

These development-only launchers use PHP's **development server**, bound exclusively to `127.0.0.1`, and do not start workers. Use the normal Caddy launcher above for the complete foundation runtime. Do not change the bind address to expose this phase on the network.

## Runtime and dependencies

Tested on Windows with PHP **8.2.0** / SQLite **3.39.2** and on Debian Linux with PHP **8.2.34** / SQLite **3.40.1**; both use Caddy **2.11.7**, Symfony **7.4.20**, Doctrine DBAL **3.10.6**, and PHPUnit **11.5.57**. Dependencies are pinned in `composer.lock`. Symfony 7.4 is the supported LTS compatible with PHP 8.2 ([official release information](https://symfony.com/releases/7.4)). Update the old installed Windows PHP patch release before LAN deployment; no system PHP installation was changed.

Required extensions are checked by `scripts/Check-Dependencies.ps1`: ctype, iconv, PDO SQLite, session, mbstring, DOM, XML, and XMLWriter. Composer additionally validates package platform requirements. FFprobe was not found on PATH; this does not prevent foundation use. A tested FFprobe installation and explicit executable setting are phase 2 work.

## Files and data

| Item | Location |
|---|---|
| Application source | `src/`, `config/`, `templates/`, `public/` |
| Migration source | `migrations/` |
| Development database | `var/dev/library.sqlite` |
| Development secret / sessions / cache | `var/dev/app.secret`, `var/dev/sessions/`, `var/dev/cache/` |
| Development HTTP logs | `var/dev/server-output.log`, `var/dev/server-error.log` |
| Isolated automated-test data | `var/test/` |
| Default data for direct PHP commands | `%LOCALAPPDATA%/MovieLibrary` |
| Default data for direct PHP commands on Linux | `$XDG_DATA_HOME/movie-library`, fallback `~/.local/share/movie-library` |
| Normal launcher database / secret / sessions | `library.sqlite`, `app.secret`, `sessions/` under the platform data directory above |
| Normal launcher logs / runtime ownership | `logs/{http,php-fastcgi,filesystem,metadata}.log`, `runtime.json` under the platform data directory |
| Backup outputs | Not implemented yet |

The development launcher explicitly sets `APP_DATA_DIR` to `var/dev`; tests force `var/test` regardless of inherited settings. To choose another local data directory for development, set `$env:APP_DATA_DIR` before both start and stop. Keep SQLite on a local disk. For manual console commands against the development database, set it as follows:

```powershell
$env:APP_DATA_DIR = 'D:\MovieLibrary\var\dev'
php bin/console doctrine:migrations:status
```

`.env` contains safe defaults; `.env.example` documents optional overrides. `.env.local`, secrets, generated data, dependencies, and logs are ignored by `.gitignore`. Only `public/` may be served as a web root. Secrets are generated locally and never included in the source. Windows filesystem ACL hardening is part of the release phase.

## Checks

```powershell
composer test
$env:APP_DATA_DIR = 'D:\MovieLibrary\var\dev'
composer check
composer validate --strict
```

Tests use synthetic paths and a separate SQLite database. They do not scan, change, or remove media. `composer check` validates dependency injection and Twig templates. Detailed acceptance coverage and manual limitations are in `docs/VALIDATION.md`.

## Implementation decisions

- Migrations cover owner/settings/locations, catalog identities and copies, matching/cache, audit history, queues and worker health. Schema constraints are tested; feature services and catalog screens arrive in the later phases.
- Doctrine DBAL and Migrations implement current persistence; ORM mappings will be introduced with catalog entities if useful. This retains the specified Symfony/Doctrine/SQLite stack.
- The current Twig forms require no JavaScript or external CDN. HTMX will be added for job progress and partial page updates when those features exist.
- Manual path entry is implemented. Host folder browsing, bounded accessibility checks, exclusions, relocation, and removal semantics are tracked in phase 2. Current paths are honestly displayed as not yet checked.
- TMDB configuration, match correction, LAN access, host actions, and backup/restore remain pending. Worker diagnosis is available in Settings and documented in `docs/RUNTIME.md`.

For the next implementation session, start with the **Next task** in `IMPLEMENTATION.md`, update its checkboxes as work lands, and add evidence to `docs/VALIDATION.md`. Linux runtime validation uses an isolated Debian container running native Linux PHP/Caddy processes; desktop integration and real storage are not covered by that environment.
