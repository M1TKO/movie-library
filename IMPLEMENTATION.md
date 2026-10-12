# Implementation progress

Last updated: 2026-10-12. Baseline: `Movie-Library-Specification.md` v1.3.

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

**Phases 2, 3 and 4 implementation are complete; Phase 5 has started.** Phase 3 now includes successful-directory discovery reconciliation, paginated run/file/title history, global job status, cooperative cancellation, result-processing heartbeats and durable partial results. Automated Windows fixtures cover missing/restored transitions, failed subtrees, overlap, concurrent dispatch, cancellation and interruption. Cross-platform acceptance remains pending; real ACL denial, offline drives, hanging shares and large-library performance have not been verified.

**Next task: continue Phase 5 TV navigation and watched actions.** Combined library filters, safe sorting, stable pagination, poster/table layouts, distinct filtered physical-file/storage totals, availability counts and copy-path controls are implemented. Complete season/episode navigation and availability breakdowns, watched/unwatched actions with bulk preview, whole-library watched totals and optional host actions. BrowserKit checks cover rendered controls; real-browser clipboard, keyboard and narrow-screen validation remains pending. Use synthetic fixtures; never scan arbitrary owner drives.

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
- [x] Catalog repositories/domain services and optional ORM mappings against the existing schema.
- [x] Keep unlinked/unresolved files visible independently of title identification.
- [x] Add Symfony Messenger Doctrine transport, filesystem/metadata/failure queues, and exclusive worker locks.
- [x] Persistent runs/options/root results and per-file outcomes; atomic job coalescing and idempotency.
- [x] One filesystem worker per data directory, ownership/idle heartbeat, worker health UI.
- [x] Bounded scan handler duration, active-job heartbeat/keepalive and ownership-aware recovery before long-running jobs are enabled.
- [x] Add host folder browser; bounded root-access validation; save inaccessible roots with warning.
- [x] Per-root exclusions, supported extensions, recursion, junction/symlink avoidance.
- [x] Manual scan all/one; queue immediately, show progress with locally bundled HTMX.
- [x] Bounded root subprocesses so an unavailable share cannot hang the worker indefinitely.
- [x] Upsert normalized physical paths; preserve memberships across overlapping roots.
- [x] Cancellation and partial traversal never mark unseen files missing.
- [x] Location edit/disable/removal/relocation semantics preserve files and history. Relocation previews relative paths, requires explicit confirmation and queues verification; overlapping memberships and destination collisions are rejected atomically.

### 2B: parsing, inspection, browsing

- [x] Tested parser for title/year, release tags, folder fallback, SxxExx, 1x03, specials, multi-episode files, Unicode, numeric titles, unresolved cases.
- [x] FFprobe installation validation and configurable executable; argument-array Process invocation, timeout, main stream selection.
- [x] Store exact bytes, dimensions, duration, codecs, inspection timestamp/error; retain corrupt files.
- [x] Incremental size/mtime inspection; handle changing files without inventing metadata.
- [x] Offline library pagination, distinct titles/copies, unresolved counts, movie/TV detail pages.
- [x] Preserve manual match locks and watched state across upserts.
- [x] Test synthetic discovery, overlap, repeat scans, formats/exclusions, multi-episode links, and probe failures.

Exit evidence: offline manual discovery through the browser; AC-03–07, AC-10–11, AC-17, AC-21, AC-23, AC-27 coverage, with explicit partial statuses as appropriate.

## Phase 3 — Availability and history

- [x] Backend launch once-per-launch and fresh-library-session check triggers, 60-second debounce/coalescing.
- [x] Check only known paths; never recursively discover or re-probe during availability checks.
- [x] Separate last-known state from latest outcome; missing/offline/access-denied/failed/not-monitored/unknown reasons.
- [x] Respect overlapping enabled roots and changed scan scope.
- [x] Reconcile only successfully listed areas; distinguish root failures from per-file outcomes. Unscanned overlaps, failed subtrees, changed scope and incomplete/cancelled scans cannot establish absence.
- [x] Preserve last-seen and restoration transitions; do not repeat missing events for unchanged files.
- [x] Cooperative cancellation, interruption recovery, durable partial results, retry; keep worker/run heartbeats alive during result processing.
- [x] Paginated run list, progress, summaries, every-file outcomes (including unchanged), title/file event history and global background-job indicator.
- [x] Automated synthetic offline/timeout, cancellation, concurrent dispatch and worker-restart validation.
- [ ] Real ACL denial, offline-drive/hanging-share validation and updated Linux execution; these are acceptance checks, not remaining implementation.

Exit evidence: AC-12–19; no false missing transitions under errors or incomplete work.

## Phase 4 — Online metadata

- [x] Backend-only TMDB credential storage/validation, language settings, attribution.
- [x] HttpClient provider interface, cache, bounded retries/backoff/rate limits, separate enrichment queue.
- [x] Search ranking with evidence/thresholds, pending/ambiguous/unmatched states.
- [x] Posters with bounded cache, confirmed IMDb IDs/links, offline placeholders.
- [x] Review/search/manual confirmation, local title edits, validated IMDb URL, explicit rematch.
- [x] Lock manual matches; merge provider identities without losing histories or watched state; distinguish unrelated reassignment.
- [x] TV season/episode lookup, multi-episode correction, specials and uncertain numbering.
- [x] Deterministic mocked tests; optional live credential test documented separately.

Exit evidence: AC-06–09, AC-23–24; local operation unaffected by provider failure.

## Phase 5 — Library experience

- [x] Poster/table views, combined search/filters, safe sorting and stable pagination; preserve filters across layout/view/page changes.
- [ ] Movie/series/season/episode navigation with all copies and availability breakdown.
- [ ] Watched/unwatched persistence; season/series bulk preview and catalog-only scope.
- [ ] Complete whole-library and filtered totals. Distinct filtered physical-file/storage totals and availability counts are implemented; whole-library watched summaries remain.
- [x] Copy path with selectable-text fallback and success/failure status on file/title detail pages; real-browser clipboard validation remains pending.
- [ ] Explicitly enabled, authenticated host actions for cataloged available files only; disable without interactive desktop.
- [ ] Keyboard, narrow-screen, loading, failure/retry, and empty-state browser validation.

Exit evidence: AC-09–10, AC-20–22, AC-25 components.

## Phase 6 — Release readiness

- [ ] Consistent SQLite backup with version manifest and secrets excluded.
- [ ] Local-owner restore validation, paused jobs, pre-restore backup, compatibility/atomic replacement checks.
- [x] Select and verify Caddy 2.11.7/PHP FastCGI on Windows/Linux; document versions and local runtime operations.
- [ ] Update/verify supported PHP patch; check web/CLI extension parity.
- [x] Production launch/shutdown with two queue consumers, ownership, and health.
- [x] Wire backend-start availability check after startup readiness; real restart smoke checks verify once-per-launch behavior.
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
