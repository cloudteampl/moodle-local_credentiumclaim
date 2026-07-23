# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.2.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.2.0
[1.1.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.1.0
[1.0.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.0.0
