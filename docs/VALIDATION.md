# Validation record

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
