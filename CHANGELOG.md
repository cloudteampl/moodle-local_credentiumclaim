# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/cloudteampl/moodle-local_credentiumclaim/releases/tag/v1.0.0
