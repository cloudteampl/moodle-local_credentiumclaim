# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2026-07-24

### Fixed
- **"My credentials" disappeared from the user menu the moment a learner claimed
  the last credential.** The entry was gated on the count of *claimable*
  credentials, so collecting everything removed the only route back to what had
  just been collected — and the page it led to said "You have no unclaimed
  credentials right now", which read as "you have nothing". The entry (and the
  profile node) now appears for anyone with credentials to see, and carries a
  count only while something is genuinely waiting. Learners who have never been
  issued anything still get no entry, so the menu stays clean.
- **The page offered to message yourself.** "My credentials" ran in a user
  context, so Moodle drew its profile header above it — avatar, full name and a
  **Message** button — on a page that is about your own credentials and has
  nothing to do with messaging. The page now runs in the system context, like the
  dashboard, and its own name is the heading. The capability is still checked
  against the user context; only what gets drawn changed.

### Added
- **Claimed credentials stay listed, with an "Open in wallet" button.** The page
  used to hide a credential the moment it was claimed, so it worked as a to-do
  list that emptied itself rather than as a record of what a learner had earned.
  Claimed credentials now remain, ordered after anything still actionable, each
  linking straight to its page in the Credentium® Wallet.
- **The wallet's address is learned, not configured.** The API has no endpoint
  that states it, but every claim link it mints points into the wallet, so the
  plugin keeps the origin of the first one it sees — the origin only, never the
  claim URL itself, which is a single-use secret. A **Credentium® Wallet
  address** setting exists for the one case learning cannot cover: a site where
  nobody has ever claimed through Moodle, so there was nothing to learn from. An
  explicit setting always wins over the learned value. Where neither is known, or
  the credential predates this release, the row simply reads "Claimed" — a button
  that led nowhere would be worse than none.
- The tracking table now stores Credentium's `credentialId` alongside the
  `issueRequestId` it polls with. The status endpoint has always returned it and
  the plugin has always discarded it; it is what the wallet link is built from.
  Existing rows fill theirs in on their next status poll.

## [1.4.0] - 2026-07-24

### Fixed
- **A transient Credentium API fault no longer discards a whole sync cycle.**
  A single `HTTP 500` from `POST /api/credential-issue-requests/statuses` — a
  server-side fault the plugin cannot provoke; the endpoint answers `400` to a
  malformed body, `401` to a key without `credentials:read`, and silently omits
  identifiers belonging to another organisation — took down the entire run.
  Every tracked credential then stayed stale until the next scheduled check, so
  a learner whose credential became claimable during the blip waited out the
  full interval. Requests that fail transiently (a dropped connection, a read
  timeout, `408`, `429`, or any `5xx`) are now retried, for a total of three
  attempts, with an exponential backoff — honouring `Retry-After` when the
  service sends one and capping any single wait at 8 seconds. Deterministic
  refusals (the other `4xx`) are never retried: repeating them would only add
  load and delay the real answer. Once the service, the rate limit or the route
  to it has failed, the remaining credential groups in the same run stop
  retrying, and the whole polling phase now runs under a wall-clock budget that
  the API client enforces per attempt and per batch chunk — so category mode with
  many API keys cannot stretch one run past the time limit a manual "Check status
  now" is given, whatever the retry constants are later set to. Credentials the
  budget cuts short are left unstamped, so they are first in the next run's queue,
  and are reported as still pending.
- **An API outage is no longer reported as "Credentium did not recognise these
  identifiers".** A failed batch call and an identifier Credentium genuinely
  does not know both leave the status missing, and the report counted them the
  same way — sending administrators to re-check an API key that was never the
  problem. The two are now counted separately, and credentials left unchecked by
  a failure are reported as pending rather than as unrecognised.
- **An unreachable API is no longer reported as "HTTP 0".** When the request
  never completes (DNS, TLS, proxy, a blocked host, or a timeout) there is no
  status to quote, so the report now shows what cURL actually objected to.
- **A `2xx` response without a `results` array is no longer silently dropped.**
  It is treated exactly like a failed call, instead of looking like a clean run
  that found nothing — the failure mode that used to leave credentials
  apparently stuck with no reason shown anywhere.

### Changed
- **The report now says what kind of failure it was, and what to do about it.**
  Failures are classified as authentication, request, rate-limit, service or
  network problems, and the report's error box carries advice specific to that
  kind: widen the API key's scope, look for a plugin update, lengthen the check
  interval, wait for Credentium to recover, or open outbound HTTPS. The raw
  technical detail stays in the diagnostics table, because that is what a
  support ticket needs. The manual "Check status now" button gives the same
  diagnosis.
- **The page-load refresher never retries.** Riding out a transient fault is the
  scheduled task's job; spending a learner's page render on a second attempt to
  an API that has already failed once would only make a slow page slower. The
  page falls back to the last known statuses, exactly as before.

## [1.3.1] - 2026-07-24

### Changed
- **Status writes are lock-free again in the steady state.** 1.3.0 introduced a
  per-row lock in `apply_remote_status()` to stop the cron sync and the page-load
  refresher from double-notifying on the same processing → issued transition —
  but it paid the lock-acquire + fresh-read cost on *every* write, up to 1000
  times per cron run (noticeable on multi-node sites using DB/Redis lock
  factories). The decision is now tiered: a non-"issued" status can never
  notify, and an incoming "issued" whose caller snapshot already says "issued"
  provably transitioned in the past (snapshots only ever lag the database), so
  both cases are a single idempotent UPDATE with no lock and no extra read. Only
  a genuine transition candidate — roughly once per credential lifetime — takes
  the per-row lock and decides against a fresh read. Exactly-once notification
  semantics are unchanged and regression-tested, including a DB-read-count test
  that pins the steady state to the lock-free path. (A conditional
  `UPDATE … WHERE remotestatus = :old` driving the *notification decision* was
  considered and rejected: Moodle's DML API does not expose affected-row counts,
  so it cannot be done portably.)
- **Status writes are now monotonic.** Because writers race without a common
  lock (and always have: a slow API answer describes the past even under 1.3.0's
  all-writes lock), every status write now refuses to regress a more-advanced
  stored state (processing/unknown < issued < claimed/failed) via a guarded
  single-statement UPDATE, and the notify decision only fires when the fresh
  read shows a pre-issued state and a post-write verification confirms the
  "issued" actually stuck. A late "issued" answer landing after a concurrent
  writer stored "claimed" can no longer resurrect the credential on the
  "My credentials" page or emit a "ready to claim" notification for something
  already claimed. Poll bookkeeping (`timechecked`) is stamped even for refused
  writes, so the poll queue keeps moving. Both interleavings are
  regression-tested with stale-snapshot replays.

## [1.3.0] - 2026-07-24

### Fixed
- **A claimed credential no longer keeps advertising "Ready to claim".** Statuses
  were only refreshed by cron, so after saving a credential to the wallet the
  "My credentials" page still showed a stale *Claim* button until the next sync —
  and clicking it produced a generic error. Now:
  - opening "My credentials" re-polls the learner's own stale statuses live
    (bounded, per-row throttled, 8-second timeout, and silent on failure — the
    page always renders);
  - clicking *Claim* flags the row for an immediate re-check, so the next look at
    the list reflects the claim right away;
  - if minting a claim link fails because the credential was already claimed
    (e.g. a second click on a stale button), the learner now sees the friendly
    *"You have already claimed this credential"* confirmation instead of a red
    error, and the row is corrected on the spot.
- **The reminder banner could be silently disabled forever.** The banner honoured
  the `showbanner` setting, which lives on a custom settings form and therefore
  was never persisted until an admin saved that form — sites that enabled the
  plugin any other way showed no banner at all, with nothing to explain why. An
  unset value now counts as **on** (the documented default), the default is
  persisted on install and upgrade, and an explicit admin "off" is still honoured.
- **The "My credentials" page rendered its title twice.** The page heading now
  shows the learner's name (matching every other user-context page, e.g.
  Notifications), and the content heading appears once.

### Changed
- The **"credential ready" notification is now actionable**: the message body
  carries a *Go to My credentials* link (plain-text emails spell out the URL), so
  a learner can jump straight to the claim page from the bell popup or email.

## [1.2.0] - 2026-07-23

### Added
- **Made a ready credential impossible to miss.** A dismissible banner on its own
  was too easy to overlook, so a learner with a credential to claim is now reached
  three ways:
  - a **bell notification** (popup + email, per the learner's messaging
    preferences), sent once at the moment a credential becomes ready to claim;
  - a persistent **"My credentials (N)"** entry in the user menu (the avatar
    dropdown), shown whenever there is something to claim — and, unlike the banner,
    it does **not** disappear when the banner is dismissed;
  - the existing top-of-page banner.
- The profile "My credentials" node and the user-menu entry share one
  dismiss-independent count, so a learner who closed the banner still has a
  standing, accurate pointer to what is waiting for them.

### Notes
- All three surfaces reflect the **"Ready to claim"** state only, matching the
  banner. A credential that is still being prepared, or already claimed, is not
  advertised. The bell notification fires exactly once per credential, on the
  processing → ready transition, so re-syncing never re-notifies.
- The bell notification is **best-effort**: it is not retried if delivery fails
  (e.g. the site's mail delivery is down, the notification type is disabled
  site-wide, or the recipient is suspended), and a send failure never aborts the
  sync. The user-menu entry and banner remain the reliable surfaces for any learner
  who logs in. The report now counts both sent and failed notifications so an
  administrator can see when a push did not get through. (A learner who has simply
  turned off the notification for themselves is not a failure — Moodle records that
  as delivered-and-read.)

## [1.1.0] - 2026-07-23

### Fixed
- **Statuses could stay stuck on "Processing" forever.** The plugin kept its own
  copy of the API URL, and the connector plugin stores that URL with an `/api`
  suffix while this plugin adds its own `/api/...` prefix. A URL copied between
  the two produced `/api/api/...`, every batch status call 404'd, and the failure
  was swallowed silently — leaving credentials that were genuinely ready to claim
  showing as *Processing*. Credentials are now inherited and normalised, and API
  failures are surfaced instead of discarded.
- The status report rendered its heading twice: the page heading was overridden
  after `admin_externalpage_setup()` and then printed again in the page body.

### Upgrade note
- Because the API key is now inherited, **the connector's key must include the
  `credentials:read` scope** (the issuing plugin itself only needs
  `templates:read` and `credentials:issue`). If this plugin previously used its
  own, narrowly-scoped key, widen the connector's key in Credentium® — otherwise
  status checks return HTTP 401. Test connection and the report now name the
  missing scope explicitly.

### Changed
- **The API URL and key are no longer configured here.** Both are inherited from
  the required `local_credentium` (Credentium® Integration) plugin, so a key is
  rotated in exactly one place. The settings page shows the inherited connection
  read-only, with a link to where it is edited. Existing duplicated values are
  removed on upgrade.
- Category mode is now honoured: credentials are polled with the credentials that
  apply to their course, one batch call per distinct key.
- Plugin name and user-facing strings use the registered trademark form
  (**Credentium®**), matching the Credentium® Integration plugin.
- The plugin now ships the shared Credentium® brand icon.

### Added
- **Status check interval** setting (5 minutes – 4 hours) that rewrites the
  scheduled task's cron expression. A hand-edited schedule is detected and
  reported as *Custom* rather than silently overwritten.
- **Check status now** button on the report, so a configuration change can be
  verified without waiting for cron.
- Diagnostics on the report: inherited endpoint, schedule, task last/next run,
  and the outcome of the last run, including a warning when Credentium® does not
  recognise tracked identifiers (the signature of a key from another
  organisation).
- PHPUnit coverage for credential inheritance, URL normalisation, interval
  round-trips, and the new failure diagnostics.

## [1.0.0] - 2026-07-06

### Added
- Initial release of **Credentium Claim** (`local_credentiumclaim`).
- Scheduled task `sync_status` that discovers issued credentials from
  `local_credentium` and batch-polls the Credentium v2 API for their claim status.
- Dismissible reminder banner injected on every page via the Hooks API
  (`before_standard_top_of_body_html_generation`), backed by a per-user
  application cache for a cheap page-load lookup.
- "My credentials" page and a My-profile navigation node.
- Claim flow that mints a single-use Credentium claim link on demand and opens it
  in a new tab without ever rendering or logging the secret.
- Admin settings page (custom moodleform) with a Test Connection probe, and a
  manager-only status report.
- Full Privacy API provider and a `user_deleted` cleanup observer.
- English and Polish language packs.
- PHPUnit tests for the API client, claimable service, sync task, banner
  renderable, and privacy provider.
- Release pipeline (`deploy.sh` + GitHub Actions) and a `moodle-plugin-ci`
  workflow covering Moodle 4.5 and 5.0.

[1.3.1]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.3.1
[1.3.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.3.0
[1.2.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.2.0
[1.1.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.1.0
[1.0.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.0.0
