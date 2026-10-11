# Implementation progress

Last updated: 2026-10-11. Baseline: `Movie-Library-Specification.md` v1.3.

**Mandatory platform requirement: Windows AND Linux.** All phases must preserve native operation on both; do not add Windows-only assumptions to shared code. Use platform adapters for paths, process control, host actions and permissions. Existing Windows-only wording below describes historical validation, not reduced scope.

- [x] Host-aware path policy: Windows drive/UNC normalization; Linux absolute paths with case and filename characters preserved.
- [x] Linux foreground development launcher with dependency checks and migrations; stop with Ctrl+C.
- [x] Linux XDG/home data defaults and platform-neutral location UI.
- [x] Unit coverage of both path policies; functional fixtures select native host paths.
- [x] Execute the full test suite and development launcher on Linux (Debian container; native Linux PHP/Caddy binaries).
- [ ] Add Windows/Linux CI matrix when repository hosting/CI is configured.
- [ ] Verify production deployment, workers, FFprobe, filesystem failures, backup/restore, LAN and optional desktop actions independently on both platforms.

This file tracks implementation, not just planning. `[x]` means implemented and checked; `[ ]` means remaining. Acceptance status is maintained separately in `docs/VALIDATION.md`. Do not mark a full criterion complete based only on one component test.

## Current position

**Phase 1 is complete.** Owner authentication, location configuration, the catalog/job/audit schema, two durable queues, worker health and ownership, and supervised Caddy/PHP FastCGI launch/stop are implemented. Windows and Debian Linux tests and runtime smoke checks pass. This foundation does not yet discover or browse media; those are Phase 2 features. Linux desktop and real-drive validation remain release work.

**Next task: phase 2A, local catalog and durable manual discovery.** Build repositories/services against the existing schema, then implement run dispatch/coalescing, bounded traversal, file/membership upserts and cancellation-safe outcomes on the existing filesystem queue. Add a browser manual-scan action only once those invariants are tested. Use synthetic fixtures; never select or scan arbitrary user drives.

## Phase 1 — Foundation

- [x] Inspect environment and preserve specification.
- [x] Symfony 7.4 LTS structure, Composer lock, safe environment examples.
- [x] SQLite with migrations, foreign keys, WAL, and 5-second busy timeout on every connection.
- [x] Single owner password hashing, local first-run setup, login, CSRF-protected logout.
- [x] Protected application routes, trusted hosts, CSRF-protected mutations, browser security headers.
- [x] Responsive Twig setup/login, library landing page, locations and settings pages.
- [x] Save locations with independent recursion, content hints, enable/disable, normalized uniqueness, and overlap warning.
- [x] Offline-safe lexical path validation; display accessibility as not checked.
- [x] PowerShell dependency check and local development start/stop with process ownership and HTTP health check.
- [x] Isolated test database and foundational functional/unit tests.
- [x] Full catalog/job schema with title/copy/episode identity constraints, unresolved files, memberships, matching/cache, run outcomes and retained audit snapshots.
- [x] Versioned migration upgrades preserve the existing owner and locations.
- [x] Messenger Doctrine filesystem/metadata/failure transports, bounded retries, idempotent diagnostic round trips, per-worker Symfony locks.
- [x] Worker ownership and persistent heartbeats shown in Settings; duplicate worker rejection.
- [x] Caddy 2.11.7 and PHP FastCGI launchers on Windows/Linux with separate workers, exclusive supervisor ownership, dependency/bind checks, readiness handshake and scoped stop requests.
- [x] Real startup/shutdown/restart, private-file protection, duplicate launch and worker-crash cleanup smoke tests on both platforms.
- [x] Document production data, dependencies, worker diagnosis and reproducible Linux test harness.

## Phase 2 — Local catalog

### 2A: safe manual discovery

- [x] Migrations for movies, series, seasons, episodes, files, memberships, content links; enforce uniqueness and exactly-one content target.
- [ ] Catalog repositories/domain services and optional ORM mappings against the existing schema.
- [ ] Keep unlinked/unresolved files visible independently of title identification.
- [x] Add Symfony Messenger Doctrine transport, filesystem/metadata/failure queues, and exclusive worker locks.
- [ ] Persistent runs/options/root results and per-file outcomes; atomic job coalescing and idempotency.
- [x] One filesystem worker per data directory, ownership/idle heartbeat, worker health UI.
- [ ] Bounded scan handler duration, active-job heartbeat/keepalive and ownership-aware recovery before long-running jobs are enabled.
- [ ] Add host folder browser; bounded root-access validation; save inaccessible roots with warning.
- [ ] Per-root exclusions, supported extensions, recursion, junction/symlink avoidance.
- [ ] Manual scan all/one; queue immediately, show progress with locally bundled HTMX.
- [ ] Bounded root subprocesses so an unavailable share cannot hang the worker indefinitely.
- [ ] Upsert normalized physical paths; preserve memberships across overlapping roots.
- [ ] Cancellation and partial traversal never mark unseen files missing.
- [ ] Location edit/disable/removal/relocation semantics preserve files and history.

### 2B: parsing, inspection, browsing

- [ ] Tested parser for title/year, release tags, folder fallback, SxxExx, 1x03, specials, multi-episode files, Unicode, numeric titles, unresolved cases.
- [ ] FFprobe installation validation and configurable executable; argument-array Process invocation, timeout, main stream selection.
- [ ] Store exact bytes, dimensions, duration, codecs, inspection timestamp/error; retain corrupt files.
- [ ] Incremental size/mtime inspection; handle changing files without inventing metadata.
- [ ] Offline library pagination, distinct titles/copies, unresolved counts, movie/TV detail pages.
- [ ] Preserve manual match locks and watched state across upserts.
- [ ] Test synthetic discovery, overlap, repeat scans, formats/exclusions, multi-episode links, and probe failures.

Exit evidence: offline manual discovery through the browser; AC-03–07, AC-10–11, AC-17, AC-21, AC-23, AC-27 coverage, with explicit partial statuses as appropriate.

## Phase 3 — Availability and history

- [ ] Backend launch once-per-launch and fresh-library-session check triggers, 60-second debounce/coalescing.
- [ ] Check only known paths; never recursively discover or re-probe during availability checks.
- [ ] Separate last-known state from latest outcome; missing/offline/access-denied/failed/not-monitored/unknown reasons.
- [ ] Respect overlapping enabled roots and changed scan scope.
- [ ] Reconcile only successful areas; distinguish root failures from per-file outcomes.
- [ ] Preserve last-seen and restoration transitions; do not repeat missing events for unchanged files.
- [ ] Cooperative cancellation, interruption recovery, durable partial results, retry.
- [ ] Paginated run list, progress, summaries, every-file outcomes (including unchanged), title/file event history.
- [ ] Validate ACL denial, offline drive, hanging share, cancellation, concurrent dispatch and worker restart.

Exit evidence: AC-12–19; no false missing transitions under errors or incomplete work.

## Phase 4 — Online metadata

- [ ] Backend-only TMDB credential storage/validation, language settings, attribution.
- [ ] HttpClient provider interface, cache, bounded retries/backoff/rate limits, separate enrichment queue.
- [ ] Search ranking with evidence/thresholds, pending/ambiguous/unmatched states.
- [ ] Posters with bounded cache, confirmed IMDb IDs/links, offline placeholders.
- [ ] Review/search/manual confirmation, local title edits, validated IMDb URL, explicit rematch.
- [ ] Lock manual matches; merge provider identities without losing histories or watched state; distinguish unrelated reassignment.
- [ ] TV season/episode lookup, multi-episode correction, specials and uncertain numbering.
- [ ] Deterministic mocked tests; optional live credential test documented separately.

Exit evidence: AC-06–09, AC-23–24; local operation unaffected by provider failure.

## Phase 5 — Library experience

- [ ] Poster/table views, combined search/filters, safe sorting and stable pagination.
- [ ] Movie/series/season/episode navigation with all copies and availability breakdown.
- [ ] Watched/unwatched persistence; season/series bulk preview and catalog-only scope.
- [ ] Whole-library and filtered totals; count titles and physical storage correctly.
- [ ] Copy path with selectable-text fallback and success status.
- [ ] Explicitly enabled, authenticated host actions for cataloged available files only; disable without interactive desktop.
- [ ] Keyboard, narrow-screen, loading, failure/retry, and empty-state browser validation.

Exit evidence: AC-09–10, AC-20–22, AC-25 components.

## Phase 6 — Release readiness

- [ ] Consistent SQLite backup with version manifest and secrets excluded.
- [ ] Local-owner restore validation, paused jobs, pre-restore backup, compatibility/atomic replacement checks.
- [x] Select and verify Caddy 2.11.7/PHP FastCGI on Windows/Linux; document versions and local runtime operations.
- [ ] Update/verify supported PHP patch; check web/CLI extension parity.
- [x] Production launch/shutdown with two queue consumers, ownership, and health.
- [ ] Wire backend-start availability check when its phase 3 handler exists; current startup messages only verify queue infrastructure.
- [ ] Explicit LAN setting, allowed hosts/origins, login throttling, private-subnet firewall instructions; no public exposure.
- [ ] Restricted secret/data ACLs, rotating redacted logs and retention controls.
- [ ] Settings for probe concurrency, extensions/exclusions, page size, cache limits and diagnostics.
- [ ] 10,000-file fixture benchmarks with hardware/timings recorded against specification targets.
- [ ] Real external-drive, UNC/mounted shares, long/Unicode path, permissions and host-player checks on Windows and Linux.
- [ ] End-to-end browser workflow, backup/restore, no-media-write audit, complete acceptance matrix.
- [ ] Final operational documentation: TMDB, scanning, correction, workers, network, backups and recovery.

Exit evidence: all AC-01–28 validated; environment-dependent exceptions explicitly recorded and resolved before claiming release complete.

## Continuation rules

1. Read this tracker and validation record; inspect existing code before changing it.
2. Implement the next bounded subsection, preserving completed behavior and migrations.
3. Add meaningful tests for invariants and failures, run the relevant checks, and record actual outcomes.
4. Update checkboxes and next task in the same change. Separate implemented, automated-tested, manually verified, and still unverified.
5. Keep this specification's media read-only boundary and manually selected discovery roots throughout all phases.
