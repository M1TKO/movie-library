# Personal Movie and TV Library — Detailed Project Specification

Version: 1.3  
Date: 11 October 2026  
Status: Implementation baseline  
Target platforms: Windows and Linux hosts with access through a modern web browser

## Platform amendment — owner requirement, 11 October 2026

Windows and Linux are both required host platforms. This amendment supersedes Windows-only assumptions elsewhere in this document; it does not require a framework change. All core features must work natively on both platforms without Docker or WSL being required.

- Use host-specific absolute paths: Windows drive/UNC paths and case-insensitive comparison; Linux absolute paths and case-sensitive comparison. Preserve original spelling. Linux network storage is accessed through mounted filesystem paths.
- Provide PowerShell startup/shutdown on Windows and shell commands/scripts on Linux. Select and validate an appropriate production HTTP/PHP deployment and workers on each platform.
- Default application data to `%LOCALAPPDATA%/MovieLibrary` on Windows and `$XDG_DATA_HOME/movie-library` (fallback `~/.local/share/movie-library`) on Linux, with an explicit override. Keep SQLite on local storage.
- Implement filesystem access, process launch, timeouts, permissions, and optional host actions through platform-aware services. Host actions are optional where no interactive desktop is available; copying paths remains available.
- Apply equivalent private-network firewall restrictions on Linux; Windows Firewall instructions apply only to Windows.
- Run automated tests and validate setup, persistence, discovery, availability, FFprobe, workers, backup/restore, and deployment on both operating systems before declaring release readiness. Record platform-specific results separately.

## Implementation handoff — read first

This file is a self-contained specification. A new developer or coding assistant does not need the original conversation to implement the application. The latest stack decision is **PHP + Symfony + SQLite + FFprobe**, with a **Twig/HTMX browser interface**. This supersedes earlier Python/FastAPI and PHP/Laravel suggestions. Do not switch frameworks without an explicit new instruction from the owner.

The original project workspace is `D:\MovieLibrary`. This is the source-code workspace, not an instruction to scan that drive or directory for videos. If this document is used in another workspace, use that workspace instead. Actual media roots must be selected by the owner through the application. No media folder list, TMDB credentials, owner password, or real filename samples have been supplied with this document.

At this handoff, the deliverable is a specification; application implementation and live validation have not yet been performed. Do not assume that dependencies, API credentials, a database, or a working application already exist. Inspect the destination workspace and preserve any existing work before implementing.

The confirmed product requirements are in Section 2. Other sections supply the implementation baseline and proposed defaults needed to make them concrete. Use these defaults unless the environment requires a justified adjustment; document adjustments in the README. Do not restart requirements gathering for choices already settled here. Missing API credentials or real media files must not block implementation: support offline operation and use synthetic fixtures for validation.

### Copyable prompt for a new chat

> Implement the application described in the attached Movie-Library-Specification.md. Read the entire document first and treat it as the project baseline. Use PHP and Symfony, SQLite with Doctrine, FFprobe, Twig/HTMX, and Symfony Messenger. Build the actual working application, including background jobs, setup and launch scripts, migrations, tests, and documentation. Preserve all stated requirements, especially manual discovery scans, automatic startup availability checks, retained missing-file records, movies and TV episodes, and detailed scan history. Inspect the workspace before changing it, use the documented defaults for routine choices, and do not require TMDB credentials or real media to complete offline functionality. Test the implementation against the acceptance criteria and report completed items, unverified environment-dependent items, and remaining limitations accurately.

## 1. Purpose

Build a personal web application that catalogs movie and television video files stored in user-selected folders. The application runs on the user's computer and is accessible from that computer and other devices on the home network. It identifies titles through an online metadata service, preserves each file's location, displays useful media information, and maintains a permanent record even when files or drives become unavailable.

The library is intended for a single owner and a collection of up to 10,000 video files. Movies and episodes may have multiple physical copies. A catalog entry represents the title or episode, while file records represent its physical copies.

This document specifies the product, proposed implementation, operational behavior, and acceptance criteria. Items described as proposed defaults are implementation choices that may be adjusted without changing the agreed product scope.

## 2. Confirmed requirements

| ID | Requirement |
|---|---|
| REQ-001 | Run locally on Windows and Linux computers and provide a browser interface. |
| REQ-002 | Allow access from the host and home network; do not require public internet exposure. |
| REQ-003 | Allow the owner to choose and persist multiple media folders. |
| REQ-004 | Support movies and TV series, including seasons and episodes. |
| REQ-005 | Discover new content through manually initiated scans. |
| REQ-006 | Automatically check known file locations whenever the library is opened and when the backend starts. |
| REQ-007 | Retain unavailable content in the catalog with a meaningful status. |
| REQ-008 | Identify content using TMDB, with a user-supplied free API credential. |
| REQ-009 | Show correct titles, posters when available, and IMDb links when identifiers are available. |
| REQ-010 | Flag uncertain matches and allow manual correction. |
| REQ-011 | Preserve full file paths, sizes, and video resolutions. |
| REQ-012 | Support watched and unwatched status. |
| REQ-013 | Keep detailed histories of scans, availability checks, and file changes. |
| REQ-014 | Support up to 10,000 video files with responsive browsing. |
| REQ-015 | Show catalog totals and searchable, filterable movie and TV lists. |
| REQ-016 | Always support copying paths; support host-side folder opening and playback where feasible. |

## 3. Scope and boundaries

### 3.1 Included in the first release

- Folder configuration, recursive discovery, configurable exclusions, and manual rescanning.
- Automatic background checks of previously cataloged files.
- Movie and episode filename parsing and TMDB matching.
- Review and correction of ambiguous or unmatched content.
- Poster and table views, details pages, search, filters, sorting, and pagination.
- Per-file availability, technical metadata, and multiple copies.
- Watched status for movies and episodes, with season and series bulk actions.
- Scan history, individual title history, and errors with reasons.
- Copy-path actions and Windows host actions.
- Settings, metadata credentials, backup, restore, and documented startup instructions.

### 3.2 Outside the first release

- Public internet hosting or access, router port forwarding, or public discovery.
- Browser video streaming, transcoding, or casting.
- Downloading movies, subtitles, or torrent integration.
- Renaming, moving, editing, or deleting media files.
- Automatic recurring full scans or real-time filesystem monitoring.
- Multiple independent user profiles, shared ratings, or social features.
- Personal ratings, favorites, custom tags, recommendations, and playback progress tracking.
- Guaranteed identification of every filename or recovery of deleted media.
- Disc-image and DVD/Blu-ray folder interpretation; ISO files are outside the default discovery set.

These features may be added later, but are not prerequisites for completing the agreed first release.

## 4. Technology stack

| Component | Proposed choice | Purpose |
|---|---|---|
| Language | PHP | Backend, filesystem scanning, metadata matching, and jobs. |
| Web framework | Symfony | HTTP API, validation, authentication, dependency injection, and serving the interface. |
| Server | Windows-compatible HTTP server with PHP FastCGI | Local and LAN service; exact server selected and verified during implementation. |
| Database | SQLite | Persistent catalog, settings, watched state, and history. |
| Database layer | Doctrine ORM/DBAL with Doctrine Migrations | Structured queries, relationships, and versioned schema changes. |
| Media inspection | FFprobe from the FFmpeg distribution | Read actual dimensions, duration, and codec information. |
| Browser interface | Symfony Twig templates, HTMX, small amounts of JavaScript, and CSS | Responsive interface without requiring a Node.js production runtime. |
| Online metadata | TMDB API | Primary movie, series, season, episode, image, and external-ID source. |
| HTTP client | Symfony HttpClient | Bounded requests, timeouts, retry handling, and API integration. |
| Background jobs | Symfony Messenger with Doctrine transport and a PHP CLI worker | Durable queues for scanning, availability checks, and enrichment without blocking web requests. |
| Job coordination | Symfony Lock plus persistent run records | Prevent overlapping filesystem jobs and combine duplicate requests. |
| Filesystem and processes | Symfony Finder, Filesystem, and Process components | Traverse selected roots, handle paths, and launch FFprobe safely. |
| Filename parsing | Tested PHP parsing service with explicit filename and folder rules | Extract likely title, year, season, episode, and release tags without a Python dependency. |
| Dependency management | Composer with a committed lock file | Reproducible PHP dependencies. |
| Testing | PHPUnit, Symfony WebTestCase, and browser smoke tests | Verify scan semantics, matching, persistence, and core workflows. |

FFprobe, distributed with FFmpeg, is the required video-inspection tool. The application needs media inspection, not video conversion.

Use maintained compatible PHP and Symfony versions at implementation time, preferring a supported Symfony LTS release when compatible with the selected PHP runtime. Pin dependencies through Composer and record the tested PHP, Symfony, SQLite, and FFmpeg versions. Exact versions must be verified when development begins rather than assumed from this specification. Python and FastAPI are not required.

Run the HTTP server and PHP CLI queue worker as separate processes under the intended Windows account. Use a single filesystem worker initially; keep metadata enrichment in a separate queue so online lookups do not delay availability checks. The launcher must start or verify both processes and enqueue the backend-start availability check once per application launch, not once per PHP HTTP request. Use PHP's development server only for development, not as the normal LAN deployment server.

## 5. Deployment and access model

### 5.1 Host computer

- The backend, database, settings, and cache reside on the Windows host.
- Filesystem operations run using the permissions of the Windows account running the backend.
- Local folders, attached external drives, mapped drives visible to that account, and accessible UNC shares are supported.
- The SQLite database must remain on a local host disk, not on a network share.
- A proposed default address is `http://localhost:8000` on the host and `http://<host-LAN-address>:8000` on other devices.
- The host must be awake and the backend running for LAN access.

### 5.2 Home-network access

- Provide an explicit LAN-access setting and document its effect.
- Restrict any Windows Firewall rule to the private network profile and local subnet.
- Do not create router forwarding rules or expose the service publicly.
- Use a single-owner login for LAN access. Proposed default: require that login on localhost as well.
- First-run owner setup must occur locally, before LAN access is enabled.
- Store a password hash, never a plaintext owner password.
- Protect state-changing endpoints against cross-site request forgery; restrict allowed hosts and origins.
- Keep metadata credentials on the backend and redact them from logs and API responses.
- Plain HTTP is acceptable for the agreed trusted-home-network deployment; encrypted local access may be added later.

### 5.3 Host actions

“Open folder” and “Play” act on the Windows host, even when requested from another device. They do not open files on the phone or laptop viewing the page. Label them accordingly.

These controls are authenticated and explicitly enabled in settings. They launch only cataloged, currently available files or their recorded containing folders. Never accept arbitrary shell commands from the browser. Background or service deployments may lack an interactive desktop; in that case disable these controls and retain Copy path.

## 6. Folder management

### 6.1 Folder records

For each selected location, store:

- Stable internal ID and user-visible label.
- Original path and normalized path used for comparison.
- Enabled state.
- Include-subfolders setting; proposed default: enabled.
- Content hint: Mixed, Movies, or TV series.
- Exclusion settings.
- Last discovery scan and last availability check times.
- Current location availability and last error.

### 6.2 Folder selection

- Provide a backend-powered folder browser and manual path entry.
- Display folders on the host computer, not folders on the device running the browser.
- Do not upload video files to add a location.
- Validate that a path is absolute and report whether it is currently accessible.
- Permit saving an offline location with a visible warning so external drives can be configured in advance.
- Warn about overlapping roots and prevent duplicate physical-file records when overlaps are scanned.
- Normalize Windows paths case-insensitively while preserving their original display spelling.
- Support Unicode, spaces, and long paths where supported by the operating system.
- Do not traverse junctions or symbolic links by default; avoid loops if that option is later enabled.

### 6.3 Removing or disabling locations

Disabling a location stops future checks and discovery for that location while preserving the catalog. Removing a location configuration also preserves its existing file records by default, marked as no longer monitored. A separate explicit catalog-removal action may remove records, but never deletes the underlying media.

Drive letters may change. Provide a way to update a location's root path while preserving relative paths and history, then verify the proposed new paths. Do not silently assume that a newly attached drive is the old drive merely because its letter matches.

## 7. Discovery and scanning

### 7.1 Supported files

Default extensions: `.mkv`, `.mp4`, `.avi`, `.mov`, `.m4v`, `.mpg`, `.mpeg`, `.wmv`, `.ts`, `.m2ts`, and `.webm`.

Match extensions case-insensitively. Allow configuration of the supported set. Exclude subtitle, image, executable, and unrelated files. Trailer and sample exclusions use explicit filename or folder rules, with a visible way to override them. Do not discard files solely because their size is small; legitimate short episodes and films must remain discoverable.

### 7.2 Manual scan controls

- Scan all enabled locations.
- Scan one selected location.
- Cancel the current scan cooperatively.
- View progress and partial results.
- Retry a failed scan or selected lookup failures.

Only one filesystem job runs at a time in the first release. If a check is already queued or running, combine duplicate requests or show the current job. Queue a requested full scan after an active availability check. Prevent competing jobs from changing the same file states simultaneously.

### 7.3 Scan stages

1. Create a persistent run record with selected roots and options.
2. Test each root and record accessibility.
3. Enumerate candidate files under accessible roots, honoring recursion and exclusions.
4. Upsert file records using normalized path identity; link location memberships without duplication.
5. Read file size and modification time.
6. Parse filename and relevant parent-folder names.
7. Inspect new or changed files with FFprobe.
8. Match previously unidentified titles and queue online enrichment.
9. Reconcile unseen known files only for successfully enumerated areas.
10. Commit the final run summary, retaining per-item outcomes and errors.

Filesystem discovery must not wait indefinitely for online metadata. Make discovered files visible before enrichment finishes, with matching status Pending when appropriate. Show discovery and enrichment progress separately.

### 7.4 Incremental behavior

- An unchanged normalized path, size, and modification time should reuse existing technical metadata.
- Do not repeat successful title lookups on every scan.
- Preserve manual title matches and watched state.
- A size or modification-time change triggers reinspection, but does not blindly replace a manually locked match.
- A returning file changes to Available and receives a restoration event.
- In-progress downloads or files that change during inspection remain cataloged with a retryable inspection status.
- No full-file hashing by default: reading every video end to end is unsuitable for routine scans.

### 7.5 Moves and duplicates

Treat different paths as different physical copies unless a move can be established with strong evidence. Size and filename alone are insufficient to prove identity. A confirmed path change preserves file history; otherwise retain the old missing record and add the new record, optionally suggesting a possible move for review.

Multiple copies of one title appear together. Watched status belongs to the movie or episode, not to each copy. Duplicate detection must not delete or alter files.

### 7.6 Cancellation and partial failures

Keep completed discoveries when a job is cancelled. Do not convert unseen records into Missing based on an incomplete traversal. If a subtree fails, only successfully verified areas can establish missing files. An inaccessible root or permission error must not produce mass deletion or missing events.

## 8. Automatic availability checks

### 8.1 Triggers

- Start a background check when the backend starts.
- Request a background check whenever a fresh library browser session is opened.
- Do not trigger a check for every page navigation, filter change, or API request.
- If an equivalent check or full scan is already running, attach the interface to that job instead of launching another.

The library renders saved data immediately. Show “Checking availability,” the last completed check time, and progress. A proposed short debounce of 60 seconds prevents several tabs opening together from repeating identical work.

### 8.2 Check scope

Check saved file locations in enabled roots. A startup check does not recursively discover new files, re-probe unchanged videos, or refresh online metadata. New content is discovered through manual scans.

### 8.3 Availability model

| Status | Meaning | Required presentation |
|---|---|---|
| Available | The file is accessible at the recorded path. | Normal file actions. |
| Missing | Its parent location was checked successfully and the recorded file is absent. | Missing badge, path, last-seen date. |
| Location offline | Its drive or network root cannot currently be reached. | Offline-location badge and root information. |
| Access denied | Permissions prevented checking the path. | Permission explanation. |
| Check failed | Another error prevented a reliable conclusion. | Error reason and retry option. |
| Not monitored | Its location is disabled or no longer configured. | Last known state and monitoring notice. |
| Not yet checked | No successful availability check has occurred. | Unknown state. |

Store last known availability separately from the latest check outcome so an error does not erase useful history. All failed checks include a reason; raw diagnostic detail may be placed in an expandable section.

A title with at least one available copy is Available overall. A title with only missing copies is Missing. If remaining copies are offline or uncheckable, use Unavailable or Unknown with a breakdown rather than claiming deletion. Series availability is summarized as counts of available, missing, and uncheckable cataloged episodes.

## 9. Identification and metadata

### 9.1 Provider choice

Use TMDB as the initial provider. Its API is free for noncommercial use with attribution under its applicable terms. The owner supplies credentials obtained through their own TMDB account. Provide setup guidance, credential validation, and the required attribution in the application.

Do not require additional providers to ship the first release. Keep the provider interface modular so TVmaze or OMDb can be added later.

### 9.2 Parsing and matching

- Extract title, year, content type, season, and episode numbers where present.
- Remove recognized resolution, codec, source, and release-group tags from the search query.
- Preserve the original filename and parsed fields for troubleshooting.
- Use directory names as supporting evidence when filenames are insufficient.
- Search the correct movie or TV endpoint based on parsed evidence and folder hints.
- Rank candidates using normalized title similarity, release year, content type, and episode context.
- Auto-accept only sufficiently strong matches with no close competing candidate.
- Flag ambiguous, incomplete, or conflicting results for review.
- Establish scoring thresholds against representative examples during implementation; record the reason for each automatic decision.
- Never fabricate an IMDb identifier or treat an IMDb search URL as a confirmed title URL.

### 9.3 Review workflow

Matching states: Pending, Auto matched, Manually confirmed, Needs review, and Unmatched.

For a review item, show the original filename, parsed title, year, folder, and candidate titles with posters. Allow the owner to select a result, change the search text, specify movie versus TV, or leave it unmatched. Allow local title edits and a manually supplied validated IMDb URL when no provider match exists.

Manual selections are locked against automatic replacement. An explicit Rematch action can reopen matching. Technical rescans do not clear that lock.

### 9.4 Stored metadata

Store display title, original title when available, release year or air date, synopsis, genres, provider IDs, IMDb ID when supplied, poster reference, metadata language, source, and last refresh time. Keep optional fields nullable.

For TV, store series identity, seasons, episode numbers, episode titles, and air dates where available. Display a series IMDb link when an episode-specific ID is unavailable, and label it as a series link.

### 9.5 TV edge cases

- Support `S01E03` and `1x03` patterns.
- Support one file representing multiple episodes, such as `S01E01E02`.
- Allow season zero for specials when provider data supports it.
- Flag absolute numbering or date-based episode names when they cannot be mapped confidently.
- Permit manual episode assignment.
- Catalog physically discovered episodes; do not count every unowned episode in a provider's episode guide as a missing file.

### 9.6 Provider failures and offline behavior

Use bounded timeouts, limited retries, exponential backoff, and provider rate-limit responses. Cache successful metadata and avoid sending file contents, full paths, or the entire library to the provider. Send only the search information needed to identify titles.

If internet access or credentials are unavailable, scanning and local catalog browsing must still work. New entries retain parsed names and Pending or Unmatched status. Offer a retry-enrichment action when access is restored.

## 10. Media information

FFprobe runs through Symfony Process with argument arrays, a timeout, and bounded concurrency. Never construct a shell command from a filename. Proposed default: two concurrent probes, adjustable downward for slower disks.

Read and store:

- Exact byte size from the filesystem.
- Duration in seconds.
- Primary video stream width and height.
- Video codec and, when reliably available, frame rate.
- Optional audio codec and channel information.
- Inspection status, timestamp, and failure reason.

Choose the main video stream rather than attached poster art. Display exact dimensions and a derived label such as 720p, 1080p, or 2160p. Explain that the label is a display classification; exact dimensions remain authoritative, particularly for cropped films.

Display decimal GB consistently, with exact bytes in a tooltip or details area. Store raw values so formatting can change later. Unknown duration or resolution must not prevent a file from appearing in the library.

## 11. User interface

### 11.1 Navigation

Primary pages: Library, Movies, TV Series, Needs review, Locations, Scan history, and Settings.

Show a compact active-job indicator across pages. The interface must work on desktop browsers and smaller home-network devices. Use clear labels, keyboard-accessible controls, visible focus states, and text alongside availability colors.

### 11.2 Library views

- Toggle poster grid and compact table views.
- Search by title, original title, and filename.
- Filter by content type, watched state, availability, matching state, location, and resolution.
- Combine filters and show a clear reset action.
- Sort by title, release year, date added, size, and last checked time where meaningful.
- Use stable server-side pagination; proposed page sizes are 25, 50, and 100.
- Preserve filters and view mode during navigation.
- Use placeholder artwork for unmatched titles or unavailable posters.

### 11.3 Movie detail page

Show poster, title, year, description, genres, watched state, matching status, IMDb link, and all file copies. Each copy shows full path, filename, size, resolution, duration, availability, last-seen time, and applicable actions. Include an expandable history section and metadata correction action.

### 11.4 TV detail pages

Show a series overview with season navigation. Each season lists cataloged episodes, watched state, availability, and copies. Multi-episode files can appear under each linked episode, but must count only once in physical storage totals.

### 11.5 Statistics and totals

Display distinct counts for movies, series, cataloged episodes, and physical files. Include available, missing, and uncheckable file counts, watched/unwatched title counts, and available-file storage usage.

Multiple copies increase file count and storage usage, not movie count. A multi-episode file increases physical file count once. Display filtered totals separately from whole-library totals. Optional last-known storage for unavailable files must be labeled separately.

### 11.6 Actions

- Copy full path with visible success or fallback selectable text.
- Open containing folder on host, when enabled and supported.
- Play with the host's default player, when enabled and supported.
- Open IMDb in a new tab when a confirmed link exists.
- Mark watched or unwatched.
- Review or correct metadata.
- View related scan events.

Launching playback does not automatically mark a title watched; a successful process launch does not prove it was viewed.

## 12. Watched status

Store watched state and the most recent watched timestamp for each movie or episode. Provide an explicit unwatched action. Watched state survives unavailable files, rescans, rematching, and replacement copies whenever the title identity is preserved.

For a season or series, derive Unwatched, Partially watched, or Watched from its cataloged episodes. Bulk marking applies to episodes currently in the catalog; newly discovered episodes start unwatched. Preview the affected episode count for bulk actions.

## 13. History and audit behavior

### 13.1 Run-level history

Every discovery scan and startup availability check has:

- Unique run ID, trigger, selected locations, and options snapshot.
- Queued, started, and finished timestamps.
- State: Queued, Running, Completed, Completed with errors, Cancelled, Failed, or Interrupted.
- Counts for files examined, new files, technical changes, missing files, restored files, unchanged files, and uncheckable files.
- Separate enrichment counts for pending, matched, review-needed, and failed lookups.
- Summary message and location-level errors.

Counts must have documented meanings and avoid mixing episode counts with physical-file counts.

### 13.2 Item-level events

Record discovery, confirmed path change, technical information change, missing transition, restored transition, failed check, match correction, watched-state change, and catalog removal. Include timestamp, run ID when applicable, item identity, relevant before/after values, path snapshot, and reason.

Preserve names and paths in event snapshots so history remains readable after catalog edits. Store one compact per-file outcome for each run, including unchanged files, to support inspecting what that run checked. Avoid duplicating full metadata payloads for unchanged results.

### 13.3 Retention and interpretation

Retain history by default, using pagination and indexes. Provide database-size visibility; history cleanup may be an explicit maintenance action with a preview. Do not silently discard old history.

Use “Missing from recorded path” instead of “Deleted” when only absence is known. Distinguish removing a catalog record from deleting a physical file. The application itself does not delete media.

## 14. Proposed data model

All timestamps are stored in UTC and displayed in the browser's local timezone. Use foreign keys, migrations, and indexes. Separate title identity from filesystem identity.

| Entity | Main fields and purpose |
|---|---|
| locations | ID, label, root path, normalized root, enabled, recursion, type hint, exclusions, check timestamps, location status. |
| movies | ID, titles, year, synopsis, provider IDs, IMDb ID, poster, matching state, manual lock, watched state, timestamps. |
| series | ID, titles, years, synopsis, provider IDs, IMDb ID, poster, matching state, manual lock. |
| seasons | ID, series ID, season number, optional provider ID and title. |
| episodes | ID, season ID, episode number, title, air date, provider IDs, watched state, timestamps. |
| media_files | ID, original and normalized full path, size, modification time, dimensions, duration, codecs, availability, last seen, last checked, probe state. |
| file_locations | File ID, location ID, relative path; supports overlapping selected roots without duplicate files. |
| file_content_links | File ID and movie or episode ID; supports multiple copies and multi-episode files. |
| match_candidates | Target item, provider ID, candidate summary, score, evidence, review state. |
| runs | Job type, trigger, state, timestamps, options, counters, and summary. |
| run_locations | Per-run root accessibility, enumeration outcome, and error details. |
| run_file_results | Run ID, file ID, compact outcome, path snapshot, and diagnostic code. |
| events | Timestamp, entity references, event type, run ID, before/after details, title/path snapshot. |
| metadata_cache | Provider, request identity, cached result, fetched time, and cache state. |
| settings | Non-secret configuration and feature flags. |
| owner_auth | Owner password hash and session-related state. |

Enforce uniqueness on normalized file paths, normalized configured roots, provider identities within content types, and season/episode identities within their parent. Validate that each content link targets exactly one supported content type. Manual unmatched entries need internal IDs independent of provider IDs.

Credentials should be stored in an appropriate local secret mechanism, or in a restricted local configuration file if a secret-store integration is not used. They must never be returned in normal settings responses.

## 15. Backend structure and API outline

Separate modules for configuration, authentication, database models, locations, discovery, availability, probing, filename parsing, metadata providers, matching, history, host actions, and UI routes.

Proposed API routes below describe capabilities; exact naming can evolve during implementation:

| Method and route | Purpose |
|---|---|
| GET /api/library | Search, filter, sort, and paginate titles. |
| GET /api/library/stats | Whole-library and filtered totals. |
| GET /api/movies/{id} | Movie details and copies. |
| GET /api/series/{id} | Series and season summary. |
| GET /api/seasons/{id}/episodes | Cataloged episodes and files. |
| PATCH /api/movies/{id}/watched | Update movie watched state. |
| PATCH /api/episodes/{id}/watched | Update episode watched state. |
| POST /api/series/{id}/watched | Bulk watched action with explicit scope. |
| GET, POST /api/locations | List and add locations. |
| PATCH /api/locations/{id} | Update path, recursion, enabled state, or exclusions. |
| DELETE /api/locations/{id} | Remove configuration with catalog-preservation semantics. |
| GET /api/folders | Authenticated host-side folder browsing. |
| POST /api/scans | Queue discovery for selected or all enabled locations. |
| POST /api/availability-checks | Queue or join a check of known paths. |
| GET /api/runs | Paginated run history. |
| GET /api/runs/{id} | Progress, summary, and location outcomes. |
| GET /api/runs/{id}/files | Paginated per-file outcomes. |
| POST /api/runs/{id}/cancel | Request cooperative cancellation. |
| GET /api/review | List ambiguous or unmatched items. |
| POST /api/matches/search | Search candidate metadata. |
| POST /api/matches/confirm | Confirm and lock a match. |
| POST /api/files/{id}/open-folder | Open the recorded folder on the host. |
| POST /api/files/{id}/play | Launch the recorded file on the host. |
| GET /api/settings | Read non-secret settings. |
| PATCH /api/settings | Update validated settings and credentials. |
| POST /api/backups | Create a consistent backup. |

Long-running requests return a job ID promptly rather than holding the browser request open. Progress may use short polling initially. Validate all sort fields, filter values, IDs, and paths; use parameterized database access. Return structured errors with stable codes and user-readable explanations.

## 16. Jobs, persistence, and performance

- Use Symfony Messenger for durable jobs, with one filesystem worker and a shared lock preventing overlapping scans and checks.
- Make message handlers idempotent: retries or redelivery must not duplicate files, history events, or completed runs. Record failures and offer explicit retry through Messenger's failure handling.
- Keep queue transactions short and configure worker retry behavior for SQLite contention; all workers share the same database concurrency policy.
- Enable foreign keys, use WAL mode where appropriate, and configure a bounded busy timeout.
- Keep write transactions short; do not hold them across network calls or media inspection.
- Execute filesystem traversal, FFprobe, and bulk metadata work in PHP CLI workers, outside HTTP request handling.
- Bound network and disk concurrency, particularly for removable and network drives.
- Batch database updates while preserving recoverable intermediate progress.
- Index title search fields, provider IDs, normalized paths, availability, watched state, and run/event timestamps.
- Do not render or transfer all 10,000 files in a single page response.
- Lazy-load artwork and cap the cache size through configurable settings.
- If the backend restarts mid-run, mark the run Interrupted and preserve committed results. Never infer missing files from the interrupted traversal.

Proposed performance targets on a typical host with a local SSD, measured against a 10,000-file fixture:

- First paginated library response within 2 seconds under normal local conditions.
- Normal search and filter responses within 1 second after database warm-up.
- Scan/check requests acknowledged within 1 second after validation.
- Job progress refreshed approximately every 2 seconds while the interface is open.
- The interface remains usable throughout scanning and enrichment.

These are validation targets, not promises for disconnected shares or failing disks. Scan duration depends on storage and provider latency. Root operations require bounded failure handling so one unavailable share cannot block all progress indefinitely.

## 17. Settings and maintenance

Settings include metadata credentials and language, locations, video extensions, exclusions, probe concurrency, LAN access, host actions, page size, poster cache location/limit, and diagnostic logging level.

Provide backup and restore for the catalog, watched status, location configuration, and history. Use SQLite's consistent backup mechanism rather than copying a live database file blindly. Include schema/application version information. Poster cache may be excluded because it can be recreated. Exclude secrets from ordinary portable backups and explain how to re-enter them.

Restore is a local-owner maintenance operation: validate the backup, pause jobs, create a pre-restore backup, restore atomically where possible, and verify schema compatibility. Restoring does not touch media files.

Provide startup and shutdown scripts, a dependency check for FFprobe, actionable error messages, and instructions for running under the intended Windows account. Windows startup integration is optional; launching the application manually must be fully supported.

## 18. Error handling and diagnostics

Handle unavailable drives, invalid paths, permission failures, corrupt or unsupported videos, FFprobe timeouts, API authentication failures, rate limits, provider outages, database errors, and application interruptions without losing the existing library.

Write rotating local logs with run IDs for correlation. Redact credentials and session secrets. Detailed logs may contain file paths, so keep them local. Do not require telemetry or upload media. Show user-readable errors and retry actions where recovery is possible.

## 19. Acceptance criteria

| ID | Scenario and expected outcome |
|---|---|
| AC-01 | On both Windows and Linux, start the app, complete local setup, and open the library in a browser. Data survives a restart. |
| AC-02 | Access the authenticated app from another device on the permitted home network without public hosting. |
| AC-03 | Add two locations, including an external or network location, with independent recursion settings. |
| AC-04 | Scan recognized video formats; unrelated files and configured exclusions do not appear. |
| AC-05 | Scan overlapping locations and repeat the scan; each physical path has only one file record. |
| AC-06 | Identify a movie from a filename containing title, year, resolution, and release tags. |
| AC-07 | Parse TV episodes and group them under the correct series and season; support a multi-episode file. |
| AC-08 | Present ambiguous matches for review; a manual correction survives subsequent scans. |
| AC-09 | Show a poster and confirmed IMDb link where supplied; absent metadata uses clear placeholders. |
| AC-10 | Show exact path, byte-derived size, actual video dimensions, and duration when inspection succeeds. |
| AC-11 | Keep corrupt or unprobeable video files cataloged with Unknown technical values and an error reason. |
| AC-12 | Reopen the library and observe a background known-file check without waiting to browse saved entries. |
| AC-13 | Remove a file from an accessible folder; its entry remains, marked Missing with last-seen information. |
| AC-14 | Disconnect an external drive; its entries report Location offline rather than mass deletion. |
| AC-15 | Deny folder access; entries show an access-related outcome without false missing transitions. |
| AC-16 | Restore an unavailable file or reconnect its drive; checking restores availability and records an event. |
| AC-17 | Add a new file; a manual scan discovers it. A startup-only check does not need to discover it. |
| AC-18 | Cancel a scan or interrupt the process; committed results survive and unseen files are not falsely marked missing. |
| AC-19 | Browse run history, per-file outcomes, and title history, including unchanged-file outcomes. |
| AC-20 | Mark a movie and episodes watched; status survives rescans, missing files, and database restart. |
| AC-21 | Multiple copies count once as a title and separately as physical files and storage. |
| AC-22 | Copy paths from any browser; enabled folder/play actions operate on the host and report failures. |
| AC-23 | Disconnect internet or omit the API key; local discovery, browsing, and availability checks still work. |
| AC-24 | Reach a provider limit; enrichment backs off and is retryable without losing discovered files. |
| AC-25 | Browse and filter a 10,000-file fixture within the stated performance targets on documented test hardware. |
| AC-26 | Back up and restore the database; titles, paths, watched status, and history are retained. |
| AC-27 | Confirm no scan, check, correction, or catalog-removal action modifies or deletes media files. |
| AC-28 | Confirm unauthenticated LAN requests cannot browse paths, trigger jobs, read settings, or launch host actions. |

## 20. Test strategy

Use temporary folders and small synthetic media fixtures rather than the owner's actual collection for automated destructive simulations. Cover filename parsing, identity normalization, overlapping roots, movie/episode matching decisions, multi-episode links, availability transitions, failed subtrees, cancelled reconciliation, history counts, manual-match persistence, and watched-state persistence.

Mock metadata responses for deterministic tests; separately perform a small live integration check using configured credentials. Verify FFprobe with known fixtures and a corrupt file. Test Windows paths with spaces and Unicode, and manually verify external-drive and LAN behavior on the deployment machine.

Exercise the browser workflow from first-run setup through scan, review, detail view, watched changes, missing-file check, history, and backup. Use a generated 10,000-file database fixture to assess browsing performance without needing 10,000 real videos.

## 21. Implementation milestones

1. **Foundation:** application skeleton, configuration, authentication, database schema/migrations, startup scripts, and basic interface.
2. **Local catalog:** locations, discovery, FFprobe inspection, multiple copies, movie/TV parsing, and persistent library views.
3. **Availability and history:** startup checks, robust missing/offline semantics, cancellation, recovery, run summaries, and detailed events.
4. **Online metadata:** TMDB credentials, lookup and caching, posters, IMDb identifiers, candidate ranking, and manual review.
5. **Library experience:** filtering, sorting, TV navigation, watched state, totals, copy-path and optional host actions.
6. **Release readiness:** backup/restore, LAN verification, 10,000-file performance checks, error handling, documentation, and acceptance validation.

Each milestone should leave a usable application. Metadata enrichment must not be required before basic local cataloging works.

## 22. Deliverables and completion definition

Deliver source code, pinned dependencies, database migrations, launch scripts, setup instructions, TMDB credential guidance, FFprobe installation guidance, home-network setup instructions, backup/restore documentation, automated tests, and a record of acceptance results.

The first release is complete when the confirmed requirements and acceptance criteria are implemented and validated, the owner can catalog the selected folders from the browser, and unavailable files remain understandable and traceable without risking the original media.

## 23. External service references

The following options were checked during requirements gathering. Their current terms, limits, and technical integration details must be rechecked when implemented:

- TMDB API FAQ: https://developer.themoviedb.org/docs/faq — free noncommercial usage and attribution guidance.
- TMDB API documentation: https://developer.themoviedb.org/docs — primary integration reference.
- TVmaze public API: https://www.tvmaze.com/api — optional future TV metadata provider with a public API.
- OMDb API key options: https://www.omdbapi.com/apikey.aspx — optional future provider; the free option listed a 1,000-request daily limit during requirements gathering.

The initial release uses TMDB. TVmaze and OMDb are alternatives, not mandatory dependencies. Posters need not originate from IMDb; the catalog provides IMDb links separately when verified identifiers are available.

## 24. Default decisions and remaining setup inputs

The following defaults make the specification implementable without additional product questions. They are proposed implementation decisions rather than additional user requests.

| Decision | Baseline |
|---|---|
| Application name | Movie Library. |
| Interface and metadata language | English initially; metadata language configurable, with original-title fallback. |
| Browser appearance | Responsive, readable poster grid and compact table; neutral styling and clear availability badges. |
| Runtime installation | Native Windows PHP and CLI workers; Docker and WSL are not required. |
| Dependency versions | Select compatible maintained releases during implementation and commit Composer's lock file. |
| Network | Localhost initially; enable private-LAN binding through setup. Proposed port 8000, configurable. |
| Authentication | One owner account; no registration, email delivery, or multiple profiles. |
| Folder recursion | Enabled for newly added roots, individually configurable. |
| Folder content hint | Mixed unless the owner selects Movies or TV. |
| Discovery scheduling | Manual only. |
| Availability scheduling | Backend launch and fresh library opening, with duplicate-run coalescing. |
| Default page size | 50 results, with 25 and 100 available. |
| Default sorting | Title ascending, with a stable internal-ID tie-breaker. |
| Default watched status | Unwatched for newly created movies and episodes. |
| Metadata provider | TMDB; other providers are optional future integrations. |
| Credential setup | Enter credentials in the app; do not register accounts or accept external terms on the owner's behalf. |
| Missing entries and history | Retain until an explicit catalog maintenance action; never delete media. |
| Filesystem concurrency | One scan/check job at a time. |
| Metadata worker | Separate queue consumer with bounded requests so discovery is not blocked. |
| Host playback/folder actions | Disabled until enabled by the owner; copy path always available. |

The only owner-specific inputs needed during setup are the owner password, selected media locations, optional TMDB credentials, and whether to enable home-network access and host actions. Actual filename samples can improve parsing later but are not a prerequisite. No public-domain name, paid metadata subscription, or cloud account is required.

## 25. First-run, packaging, and operational handoff

### 25.1 First-run workflow

1. Run a dependency check for PHP, required extensions, Composer-installed dependencies, SQLite access, and FFprobe.
2. Create writable application-data directories outside the public web root and apply database migrations.
3. Start locally and create the owner account.
4. Optionally configure and validate TMDB credentials; allow Skip and explain offline capabilities.
5. Add media locations through the host-side folder browser or path entry.
6. Offer a manual first scan and show its progress. Do not automatically scan arbitrary drives.
7. Optionally enable LAN access and show the usable host address and firewall instructions.

### 25.2 Required repository and runtime artifacts

Use normal Symfony project structure, including `src/`, `config/`, `templates/`, `public/`, `migrations/`, and `tests/`. Include `composer.json`, `composer.lock`, a safe example environment file, and a README. Serve only `public/` as the document root; the database, backups, logs, secrets, and media paths must not become static web downloads.

Provide Windows PowerShell scripts for dependency checking, starting the application and workers, stopping only this application's processes, and backup/restore or documented commands for those operations. Track owned process IDs and prevent duplicate launches. Report bind failures or stopped workers instead of leaving the owner with an apparently successful launch.

Store the database, cache, logs, and backups in a configurable application-data directory on a local disk; proposed default is `%LOCALAPPDATA%\MovieLibrary`. Development and test databases must be separate from the owner's catalog. Do not commit generated databases, credentials, logs, cache, backups, or real media to source control.

Document the required PHP extensions based on the chosen packages, including SQLite support, and verify that both the web runtime and CLI runtime load them. Document FFprobe executable discovery and allow an explicit path in settings. The PHP CLI workers must run as an account that can access the configured media roots.

### 25.3 Worker health and recovery

Expose worker health and last heartbeat in Settings and the scan interface. If a worker stops, show that queued work is waiting and explain how to restart it. Use run heartbeats or ownership records to distinguish an interrupted run from an active run belonging to another process. The launch script must not mark healthy work Interrupted simply because another browser is opened.

Define durable job boundaries and retry identifiers. Either use bounded scan batches or configure queue visibility/redelivery timeouts to exceed the maximum expected handler time with heartbeat support. Do not allow a long scan to be redelivered concurrently. Keep scan progress and cancellation flags persistent.

For filesystem operations that can hang on a network share, use an implementation that can enforce a timeout, such as an isolated CLI subprocess per bounded root operation. An HTTP timeout alone does not stop blocked filesystem work. Record a check failure when the outcome is uncertain.

## 26. Additional data and behavior clarifications

### 26.1 Catalog identity and unresolved files

Every discovered file must be visible even before a movie/series identity is known. Allow files with no content link and show their parsed name or filename in Needs review and an unresolved-content section. Do not hide them because a title record could not be created. Explicitly display unresolved-file counts so library totals remain understandable.

When a corrected provider match corresponds to an existing title, merge the content association rather than creating duplicate provider identities. Preserve all copies, histories, and manual edits. For a merge of records representing the same title, preserve Watched if either record is watched and preserve the latest watched timestamp. Reassigning a file to a genuinely different title must not silently transfer watched state to that unrelated title; let the owner decide where an existing personal state belongs if necessary.

Disabling one overlapping root does not stop checking files that are also members of another enabled root. A file becomes Not monitored only when no enabled location covers it. Changing exclusions or recursion does not prove that an existing file is missing; preserve its last known state and indicate that it is outside the current scan scope.

### 26.2 History counts

Each checked file has one primary run outcome: Added, Changed, Newly missing, Restored, Unchanged, or Uncheckable. Record secondary details separately so one file does not inflate the total processed count. A file that remains missing in a later check is Unchanged with current state Missing; it is not a new missing transition every time. Keep aggregate root failures separate from per-file counts.

Append meaningful state-change events; retain compact per-run outcomes for all examined files. A finished discovery scan can have pending enrichment, shown separately. Mark the discovery run complete when filesystem work has finished, and display linked enrichment progress without rewriting its completion time.

### 26.3 Filename test examples

These are synthetic examples, not supplied files or guaranteed online matches:

| Input | Expected parsing behavior |
|---|---|
| `Example.Movie.2020.1080p.BluRay.x264-GROUP.mkv` | Movie candidate: Example Movie, year 2020; release tags excluded from title query. |
| `Example Show/Season 01/Example.Show.S01E03.720p.mkv` | Series candidate: Example Show; season 1, episode 3. |
| `Example.Show.1x03.mp4` | Season 1, episode 3. |
| `Example.Show.S01E01E02.mkv` | One physical file linked to episodes 1 and 2. |
| `Example.Show.S00E01.mkv` | Special episode, season 0, episode 1. |
| `Example Movie (2020)/video.mkv` | Use the parent folder as supporting title/year evidence. |
| `video001.mkv` | Keep discoverable and unresolved; do not guess a confident match. |

Automated parsing tests should also cover punctuation, non-Latin names, misleading numeric titles, and filenames with no year.

## 27. Final implementation report required from the new chat

The implementing developer or assistant should finish with:

- Exact startup instructions and the local browser address.
- Locations of the source, database, configuration, logs, and backup outputs.
- How to configure TMDB, add folders, scan, and correct a match.
- How to start and diagnose background workers.
- How LAN access and host-side actions are enabled.
- Test results mapped to the acceptance criteria, clearly distinguishing automated checks from manual checks.
- Any features or environment-dependent checks that remain incomplete, with no claim of success for untested real-drive, LAN, or player behavior.

This specification is sufficient to begin implementation without access to the original chat. Environment-specific choices such as the exact Windows HTTP server and runtime versions should be selected, verified, and documented during implementation rather than left as unimplemented placeholders in the delivered application.
