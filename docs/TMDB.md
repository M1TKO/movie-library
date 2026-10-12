# TMDB metadata — Phase 4

TMDB is optional. Discovery, availability checks, technical inspection and saved catalog browsing continue without credentials or internet access. No media file is uploaded or modified.

## Setup

Open **Settings → Configure TMDB credentials and language**. Get an **API Read Access Token** from [your TMDB account API settings](https://www.themoviedb.org/settings/api); this is the bearer token, rather than the shorter v3 API key. Paste it into the password field and choose a language such as `en-US`, `bg-BG`, or `fr-FR`. Select **Save and validate TMDB**. A failed validation keeps local data available and displays a redacted error. Blank credential input keeps the saved token; Remove credential deletes it.

The token is encrypted with AES-256-GCM in `APP_DATA_DIR/tmdb.secret`, separately from SQLite. The encryption key derives from the local application secret. Neither secret belongs in a catalog backup or Git. Losing/changing `app.secret` requires entering the TMDB token again. POSIX permissions are set to 0600; Windows data-directory ACL hardening remains release work. The browser never receives the saved token. PHP OpenSSL is required and checked by dependency checks and production HTTP readiness.

Use the normal launcher to run both workers. Development HTTP launchers require a separate worker, using the same `APP_DATA_DIR`:

```text
php bin/console messenger:consume metadata --sleep=1
```

New filename candidates queue enrichment when a credential exists. Select **Queue pending titles** for previously discovered local entries. Changing language affects newly queued requests; use **Retry lookup / refresh metadata** on an identified title to refresh its metadata. Search and confirmation run in the durable metadata queue, outside the HTTP request. Only credential validation performs a small bounded synchronous provider request.

## Review and correction

Open **Review matches**, or **Review or correct metadata** on a title. Search a title and refresh after its metadata job finishes. Results show title/year, cached posters, score and evidence. Confirm a candidate to lock it against automatic replacement. Local display-title/year/IMDb corrections and Leave unmatched also lock the title. A local edit retains an existing provider identity. **Explicitly reopen matching** unlocks and searches again, retaining the previous metadata until a decision is made.

Automatic decisions require an exact normalized display/original title, exact known year, score at least 0.95 and a lead of at least 0.10. Score is 80% title similarity and 20% year evidence; a one-year difference earns only 0.4 of the year component. Unknown years, remakes, nearby candidates and conflicting existing provider identities require review. TV filenames currently omit an inferred series year, so they usually require owner confirmation. Pending, auto matched, manually confirmed, needs review and unmatched remain distinct.

Use **Correct movie/TV and episode assignment** on a file to change its content type or repair numbering. Enter a movie/series title, optional year, and for TV a season plus space/comma-separated episode numbers. Season zero represents specials. This also resolves absolute/date-based numbering once the owner verifies the mapping. File assignment replaces only catalog links; previous title/episode watched state and history stay with their identities.

Confirmed provider identities merge duplicate local titles and copies. Watched states combine for the same identity; audit snapshots remain readable and old title URLs redirect to the merged title. Filename aliases ensure future scans reuse the merged identity. Selecting a different established provider identity requires explicit reassignment confirmation. The previous title and its watched state remain separate; they are not transferred to an unrelated title.

Only locally cataloged episodes receive provider metadata. Season guides never create unowned/missing episodes. Owned TV episodes load in batches of at most 50; successful full batches queue continuation. Unsupported numbering or failed episode lookups mark the series Needs review. Retry after correcting numbering or recovering the connection. Episode IMDb links use supplied identifiers; when absent, the detail page labels the series IMDb fallback explicitly. IMDb search links, non-HTTPS and misleading hosts are rejected.

## Bounds, failures and jobs

- API requests: 5-second inactivity timeout, 10-second total limit per attempt, at most 3 attempts, exponential backoff, at most 4 requests/second per provider instance.
- HTTP 429/5xx: bounded Retry-After/backoff. Cooldowns above 5 seconds fail the job for a later manual retry; they never hold the worker indefinitely.
- Successful JSON cache: 7 days, language/request-specific, at most 2,000 entries, at most 2 MiB per response.
- Poster cache: local authenticated delivery, w342 JPEGs from the fixed TMDB image host, no redirects/arbitrary URLs, at most 2 MiB per download and 100 MiB on disk with oldest-file eviction.
- Missing/evicted/offline images show a placeholder. A poster failure does not invalidate a confirmed match.
- Full paths are rejected as search inputs; providers receive only title, language, type and provider/episode identities. Parsed source evidence stays local.
- A stopped metadata worker leaves queued requests durable. Restart marks its running jobs Interrupted; retry from the title review page. Cancellation and stale edits discard unapplied responses. File/catalog availability and watched values remain usable after failures.
- Job history shows enrichment separately from filesystem work. Review pages list recent errors; the job detail polls progress and supports cancellation. Different owner actions are rejected while a title already has an active metadata job, rather than silently dropping the requested action.

The Credits section includes TMDB's approved logo and required notice. `public/tmdb-logo.svg` is the unmodified [official short logo](https://www.themoviedb.org/assets/2/v4/logos/v2/blue_short-8e7b30f73a4020692ccca9c88bafe5dcb6f8a62a4c6bc55cd9ba82bb2cd95f6c.svg). References: [authentication](https://developer.themoviedb.org/docs/authentication-application), [movie search](https://developer.themoviedb.org/reference/search-movie), [TV details](https://developer.themoviedb.org/reference/tv-series-details), [images](https://developer.themoviedb.org/docs/image-basics), [rate limits](https://developer.themoviedb.org/docs/rate-limiting), [attribution requirements](https://developer.themoviedb.org/docs/faq).

## Validation and optional live check

Deterministic tests use Symfony MockHttpClient and a fixture provider. They cover secret encryption/redaction, cache reuse and language isolation, response bounds, retries/rate limits, conservative ranking, ambiguity/unmatched decisions, manual confirmation/locks, merging/reassignment, source alias reuse on rescans, owned specials/multi-episode lookups, stale responses, durable queue consumption, interruption recovery, authenticated/CSRF-protected forms, safe IMDb URLs, and poster cache bounds/placeholders.

A live check is optional and **has not been performed**: supply your own token through Settings, validate it, search one known movie and one TV series, confirm them, inspect posters/IMDb links and owned episode titles, then disconnect internet and verify saved browsing. Do not place credentials in tests, command lines, fixtures or logs. Linux execution of the current suite is also pending because the available Docker daemon is stopped; the existing Linux harness includes the new tests and dependencies. These limitations do not require owner media or credentials for offline implementation.
