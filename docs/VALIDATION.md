# Latest validation — 13 October 2026

Phase 5 completion review added season watched summaries and distinct storage/availability totals, omitted provider-only seasons from series navigation, and labeled season-zero episode breadcrumbs as Specials. A multi-episode file still counts once in season storage; missing copies remain cataloged.

- Full Windows PHPUnit with bundled FFmpeg/FFprobe: **101 tests, 544 assertions passed, no skips**, in **45.381 seconds** on PHP 8.2.0.
- Focused Phase 5 checks: **7 tests, 86 assertions passed** after clearing the compiled test cache.
- Container lint, all **26 Twig templates**, Composer strict validation, PHP syntax and **3 Node tests** pass.
- Linux execution remains pending: Docker engine is stopped (confirmed by the missing engine pipe). Actual desktop playback/folder launches and real-storage acceptance remain unverified. Only isolated synthetic fixtures were used.


## Phase 6 validation — 13 October 2026

- Windows PHP 8.4.26 NTS x64 was downloaded from the official PHP release and checksum-verified. The project launcher prefers this ignored runtime when present; CLI and FastCGI use the same runtime and extension configuration.
- Full Windows PHPUnit: **107 tests, 596 assertions passed**, with one existing environment-dependent skip. `composer check` passed container and Twig lint.
- Backup/restore coverage passed: consistent `VACUUM INTO` snapshots include a format/schema manifest and checksum, exclude passwords, TMDB credentials, queues, workers, cache and poster data, preserve current owner credentials, create a pre-restore backup, validate integrity/schema, pause active jobs and restore atomically. A real browser backup creation on isolated `var/phase6-browser` produced `backups/20261013-065205-9bfb74a3b426`.
- Private LAN policy, allowed hosts/origins, login throttling, restricted data ACLs, rotating redacted logs, retention maintenance and release settings have automated coverage. The browser workflow verified sign-in, Settings preference persistence, backup controls and CSRF-protected backup creation.
- A synthetic 10,000-file benchmark on Windows (AMD Ryzen 7 6800H, 32 GiB RAM, Kingston NV2 2 TB SSD, PHP 8.4.26 / SQLite 3.53.4) passed the targets: first page **167 ms**, warm library/filter responses **under 79 ms**, known-check dispatch **3.71 ms**, scan dispatch **1.77 ms**, peak memory **44 MiB**. Covering association indexes and series watched filters were added after the first run exceeded the target.
- Native production runtime smoke passed **19 checks** with Caddy 2.11.7, PHP FastCGI, both queue consumers, restart persistence, one-per-launch availability dispatch, private-file rejection, clean shutdown, and worker failure cleanup.

Linux execution remains unverified because Docker Desktop is unavailable in this environment. Real external-drive/UNC or mounted-share failures, ACL denial on real media, host-player launches, and cross-device LAN access remain manual acceptance work; no owner media was scanned or modified.

## Previous validation — 12 October 2026

Phases 2–5 implementation complete. Cross-platform and real-storage acceptance is still pending.

- Full Windows PHPUnit: **100 tests, 530 assertions passed, no skips**, in **48.696 seconds**, PHP 8.2.0, with project-local FFmpeg/FFprobe 9.0.2. Tests use isolated `var/test` data and synthetic media.
- Container lint, all **26 Twig templates**, Composer strict validation and JavaScript syntax pass. **3 Node tests pass** for clipboard success, denied-clipboard selectable-text fallback, and polling loading/failure/recovery.
- Phase 5 checks cover TV specials/season/episode navigation, missing copies, catalog-only bulk actions, watched timestamps/audit events, idempotence, persistence across kernel/database reconnections, and atomic rejection when watched state or linked episode scope changes after preview.
- Whole-library/filtered watched and derived series-state totals exclude provider-only episodes. Physical storage counts each multi-episode file once. Existing combined-filter, stable sorting/pagination, metadata, discovery and availability regressions pass.
- Host-action checks cover default/headless disablement, authentication/CSRF, rejecting missing/unknown file IDs and arbitrary actions, and literal catalog-path handling including quotes, spaces and shell metacharacters. Command preparation is tested without launching owner applications. Actual Windows/Linux desktop player and folder launches remain unverified.
- Isolated real-browser validation at `127.0.0.1:8015` used only a synthetic catalog in `var/phase5-browser`: sign-in, series/specials/episode navigation, watched/unwatched confirmation and series bulk preview/confirmation, history, keyboard activation, Copy path success with matching clipboard text, and empty filtering passed.
- Library and episode layouts were checked at a 390-pixel viewport without document horizontal overflow. Stopping/restarting only the validation HTTP server showed the polling connection failure message, retained the rendered catalog, and automatically recovered. Viewport overrides were reset and the validation server stopped.
- Browser testing found and fixed the development router's missing `app.js` allowlist entry, which had blocked clipboard JavaScript and could capture an asset URL as the post-login redirect. Invalid legacy middle-dot encoding in progress/history templates was repaired.
- Denied-clipboard fallback has deterministic JavaScript coverage; actual denied-permission browser behavior, additional browsers and Linux desktop UI remain unverified.

**Remaining acceptance:** Linux execution is blocked by the stopped Docker daemon. Real ACL denial, disconnected drives, hanging shares, 10,000-file performance, actual host playback/folder launching, LAN and backup/restore still require validation. No owner media, live TMDB credentials or owner catalog were used.

**Next implementation:** release acceptance on Linux and real storage, then the remaining release checklist in [IMPLEMENTATION.md](../IMPLEMENTATION.md). Phase 6 backup and restore implementation is complete; live deployment validation remains pending.

## Previous validation � 12 October 2026

Historical Phase 4 checkpoint, with Windows deterministic validation. The Phase 3 outstanding items described here are superseded by the latest results above. No live TMDB credential or owner media was used.

- Full Windows PHPUnit: **84 tests, 393 assertions passed, no skips**, with project-local FFmpeg/FFprobe 9.0.2. Focused Phase 4 rerun after the final dispatch-coalescing fix: **17 tests, 128 assertions passed**.
- Composer strict validation, container lint and all **17 Twig templates** pass. Fresh migration of all four versions succeeds, including metadata aliases/redirects and series audit references.
- Symfony HttpClient **7.4.20** and contracts **3.7.3** are locked; OpenSSL is declared/checked. Production runtime smoke: **19 checks passed**, including the bundled attribution asset, OpenSSL web readiness, both queues, shutdown/restart, and worker failure cleanup.
- MockHttpClient validates encrypted/backend-only credentials, rejected-token redaction, language-specific cache reuse without credentials, 503 retry, bounded Retry-After handling, oversized responses, fixed-host poster requests and offline cache reuse/placeholders.
- Deterministic ranking tests establish exact-title/year requirements, original-title matching, no automatic match with missing/wrong year, close-candidate ambiguity, unmatched results and locked owner confirmation.
- Identity tests preserve copies, watched values, history snapshots and filename aliases through merges; subsequent discovery reuses the merged identity. Unrelated reassignment keeps previous watched state separately. Stale queued/in-flight responses cannot replace a newer owner edit; cancelled work does not apply metadata.
- TV tests enrich only owned season-zero and multi-episode entries, store season/episode identities and titles, retain watched state and allow manual numbering correction without creating provider-only episodes.
- Authenticated BrowserKit workflow covers credential/language settings, CSRF rejection, unresolved movie/TV assignment, local title/IMDb correction, placeholders and series IMDb fallback, queued search and metadata progress. A **real metadata worker** consumes the durable failed-offline lookup, marks old running metadata jobs Interrupted and leaves filesystem jobs independent. Old merged URLs redirect; anonymous requests cannot read metadata pages or posters.
- Existing discovery/availability/probe regressions pass. No operation scans an owner-selected real collection or writes media.

| Phase 4 acceptance | Evidence / limit |
|---|---|
| AC-06 | Local parsing plus deterministic movie provider search/ranking/enrichment pass. Live identification is unverified. |
| AC-07 | Owned TV, seasons, specials and multi-episode enrichment/correction pass with fixtures. Live TV data is unverified. |
| AC-08 | Ambiguity/review/manual confirmation and correction lock across rescan pass. |
| AC-09 | Confirmed IMDb URL validation, episode/series fallback, authenticated local poster cache and absent-image placeholder pass. Live image delivery is unverified. |
| AC-23 | Discovery/browsing/check regressions need no token; provider failure retains local identities, watched state and copies. |
| AC-24 | Mocked 503/429 backoff, short cooldown bounds, durable failed state and manual retry pass. Actual TMDB throttling is unverified. |
| AC-27 | Correction operates only on SQLite; existing discovery tests verify synthetic media byte preservation. No new media-write operations were added. |

**Remaining environment validation:** Linux current-code execution is pending because the installed Docker daemon is not running; its existing harness installs the new dependency and runs all tests. Live TMDB validation is optional and unperformed because no owner credential was provided. A real browser's visual/end-to-end metadata workflow is unverified; functional HTML/forms are tested through BrowserKit. No complete cross-platform acceptance or release readiness is claimed. [TMDB guide](TMDB.md) documents setup, bounds, correction semantics and the optional live check.

The first sandboxed Windows production smoke attempt could not terminate owned children. Its isolated supervisor/process tree was verified and cleaned up with elevated process access; the subsequent full smoke run passed. This was an execution-sandbox limitation, not a metadata failure.

## Previous validation — 12 October 2026

Phase 2 implementation complete; Phase 3 implementation started. Windows PHP 8.2.0 / SQLite 3.39.2. Historical Phase 1 Linux results below do not validate the new Phase 2/3 code.

- Full Windows PHPUnit: **66 tests, 261 assertions passed**, including real FFmpeg/FFprobe 9.0.2 generated video and corrupt media; no skipped tests. An additional availability-timeout test passed separately (**1 test, 4 assertions**).
- Composer strict validation, container lint and all **13 Twig templates** pass.
- Supervised Caddy/PHP FastCGI runtime: **18 checks passed**. Restart with synthetic known catalog data queues exactly one backend-start check, offline checks preserve last-known state, and ordinary HTTP requests do not repeat that trigger. Both workers, private-file protection, ownership and failure cleanup still pass.
- Existing Phase 2 tests cover overlapping roots, physical identity, repeat scans, exclusions/extensions, non-recursion, unresolved files, parsing, multi-episode links, incremental inspection, changing-file retry, bounded root timeout, active cancellation/heartbeat, interruption recovery, symlinks/Windows junction avoidance, location scope changes, disabled memberships, removal and media byte preservation.
- New Phase 2 completion evidence: explicit relocation preserves file identity, links, watched/manual-lock values and path history, then known-path verification succeeds. Conflicting destination configurations are rejected without partial mutation.
- Phase 3 evidence: known-path checks do not add newly appearing files or re-probe existing copies; missing/restored transitions retain last-seen state and avoid repeated missing events; offline roots and no-longer-monitored copies retain last-known availability. Durable consumption, queued cancellation, restart interruption, timeout, session debounce, equivalent scan coalescing, authenticated/CSRF-protected manual checks and once-per-session web behavior pass.
- Completed file outcomes commit after each root; an interruption retains these commits. Root timeout/cancellation never infers absence from unfinished checks. Every completed check records unchanged outcomes as well as changes/errors.

At that checkpoint, reconciliation, event pagination, progress and concurrency/cancellation evidence were pending. Those implementation items are now covered by the latest results above; real-storage and performance acceptance remains unverified.

**Linux revalidation is pending:** Docker CLI is installed but its daemon is not running. The Linux harness includes FFmpeg and all new tests; no updated Linux result is claimed. No owner media was scanned. Tests mutate only generated synthetic fixtures. Live storage, LAN, TMDB, host playback and backup/restore remain unverified.

## Historical Phase 1 evidence

### Validation record

Date: 2026-10-11. Phase: **1 complete**. Windows: PHP 8.2.0, SQLite 3.39.2. Debian Linux container: PHP 8.2.34, SQLite 3.40.1. Both: Caddy 2.11.7, Symfony 7.4.20, PHPUnit 11.5.57.

## Executed checks

- PHPUnit: **28 tests, 94 assertions passed on each platform**, including setup/login/logout, persistence, offline roots, both path policies, anonymous access, CSRF, trusted hosts, browser headers, SQLite connection policy, catalog identity constraints, preserved audit snapshots, run deduplication and actual durable worker consumption/replay. Expected rejected-request error logs appear for the CSRF/host tests.
- Composer strict validation, container lint, all six Twig templates, and all application PHP files passed syntax checks.
- Real localhost HTTP: setup HTML and CSS served successfully; private source/config files are not static downloads.
- Windows development launcher: migrations and HTTP health check succeed; duplicate start correctly rejected, owned-process stop and subsequent restart verified. FFprobe absence is reported without blocking foundation.
- Browser: first-run setup layout inspected in Codex's browser. Owner password entry intentionally left for the owner. Full authenticated browser flow is tested through Symfony BrowserKit, not yet through a real browser.
- Caddy/PHP runtime smoke: **15 checks passed on Windows; 16 on Linux** (Linux includes an extra signal-delivery assertion). Checks cover health, matching web/CLI PHP, HTML/CSS, private config protection, duplicate supervisor/worker rejection, both queue acknowledgements, shutdown, restart persistence and failure cleanup after deliberately killing a test worker.
- Linux development launcher: started and served setup HTML successfully; its owned process was stopped.
- Windows worker-stop testing required execution outside the restrictive tool sandbox, which blocked Symfony Process's process-tree termination. The normal Windows runtime check then passed. The fixture's worker-crash cleanup was corrected to terminate the worker's complete Windows process tree.

## Acceptance matrix

| Criteria | Current evidence / remaining work |
|---|---|
| AC-01 | **Foundation verified on Windows and Linux.** Production startup/setup, automatic migrations, saved-location restart persistence and authentication pass. End-to-end media catalog restart flow awaits discovery. |
| AC-02 | **Pending.** LAN deliberately disabled. |
| AC-03 | **Partial.** Synthetic offline drive and UNC roots with independent recursion saved and persisted in tests. Real external/network accessibility and folder browser pending. |
| AC-04–11 | **Pending feature behavior.** Catalog schema and constraints exist; discovery, parser, online matching and FFprobe are not implemented. |
| AC-12–19 | **Pending.** Availability worker, cancellation/recovery and history not implemented. |
| AC-20–22 | **Pending.** Watched state, title/file totals and file actions not implemented. |
| AC-23 | **Partial foundation only.** Setup and location configuration need no internet or TMDB credential; offline discovery/checking still pending. |
| AC-24–26 | **Pending.** Provider backoff, 10,000-file benchmarks and backup/restore not implemented. |
| AC-27 | **Partial.** Implemented location operations never access media. Discovery/check/correction/catalog-removal invariants await those features. |
| AC-28 | **Partial.** Tests verify anonymous requests cannot browse locations/settings or create roots; local-only setup, trusted hosts and mutation CSRF protections pass. LAN, jobs and host actions need release validation. |

## Limitations

No owner media was supplied or scanned. No live TMDB, real external-drive/UNC accessibility, ACL denial, FFprobe, host-player, LAN, backup, or performance result is claimed. Location status is always not-yet-checked in this phase. Linux was tested in an isolated Debian container using native Linux processes, not on a desktop with external drives. Docker is a validation tool, not an application dependency. The production supervisor is validated for current infrastructure jobs; long-running scan safety and backend-start availability semantics remain later-phase work.

