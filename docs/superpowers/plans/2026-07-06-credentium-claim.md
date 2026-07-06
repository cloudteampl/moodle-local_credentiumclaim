# Credentium Claim (`local_credentiumclaim`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline) — the files share conventions (namespaces, lang keys, capability names) so a single coherent author is preferred over fan-out. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Build a Moodle `local` plugin that shows logged-in users a banner about issued-but-unclaimed Credentium credentials and lets them claim them via a per-user single-use link.

**Architecture:** Hard dependency on `local_credentium` (read its `local_credentium_issuances` table). A scheduled task batch-polls the Credentium v2 API for status and stores it locally; a per-page hook does a cheap cached lookup and injects a dismissible banner; a claim page lazily mints the single-use claim link on click.

**Tech Stack:** PHP 8.1+, Moodle 4.5–5.0 core APIs (Hooks API, `\curl`, MUC cache, Task API, Privacy API, Message... none), Mustache templates, PHPUnit, moodle-plugin-ci (CI), Bash/GitHub Actions (release).

## Global Constraints

- Component: `local_credentiumclaim`; directory installs to `{moodle}/local/credentiumclaim/`.
- `version.php`: `version=2026070600`, `requires=2023100900`, `supported=[405,500]`, `maturity=MATURITY_STABLE`, `release='1.0.0'`, `dependencies=['local_credentium'=>ANY_VERSION]`.
- Every PHP file: GPLv3 header + `defined('MOODLE_INTERNAL') || die();` + `@package local_credentiumclaim` + `@copyright 2025 CloudTeam Sp. z o.o.`.
- Namespaces: `local_credentiumclaim\…` under `classes/`; procedural helpers `local_credentiumclaim_*` in `lib.php`.
- Security: `require_login`/`require_capability`/`require_sesskey`, `PARAM_*`, parameterized DB, never log `apikey`/`claimUrl`, `no-store` on claim response, locale allow-list `{en,pl}`.
- API: header `API-KEY: <key>`, `Content-Type`/`Accept: application/json`, `CURLOPT_TIMEOUT=30`, batch ≤500 ids.
- Config namespace: `get_config('local_credentiumclaim', <key>)`.
- Release pipeline: identical to sibling (`deploy.sh` + `deploy.yml`), ZIP top-level folder `credentiumclaim/`, `version.php` single source of truth, tag `vX.Y.Z` must equal `$plugin->release`.

---

## File map

```
version.php                         plugin identity + dependency
settings.php                        admin_externalpage registration + nav
admin_settings.php                  moodleform config page (Test Connection button)
testconnection.php                  auth probe via GET /api/credential-template
mycredentials.php                   user page: list unclaimed credentials
claim.php                           mint claim link + open (POST+sesskey)
index.php                           light admin status report (viewreports)
lib.php                             procedural helpers: log, nav, config read, cache purge, claimable query
classes/api/client.php              Credentium v2 client (status batch, claim link, templates)
classes/local/hook_callbacks.php    before_standard_top_of_body_html banner injection
classes/local/claimable.php         service: discover+read claimable rows, cache
classes/output/banner.php           renderable/templatable banner
classes/task/sync_status.php        scheduled task: discover + batch poll
classes/form/admin_settings_form.php moodleform
classes/privacy/provider.php        full metadata provider
classes/event/observer.php          user_deleted cleanup
db/install.xml                      local_credentiumclaim_status table
db/upgrade.php                      empty upgrade fn
db/access.php                       capabilities
db/caches.php                       session cache 'claimable'
db/hooks.php                        hook registration
db/events.php                       user_deleted observer
db/tasks.php                        sync_status schedule
templates/banner.mustache           banner markup
lang/en/local_credentiumclaim.php   strings
lang/pl/local_credentiumclaim.php   strings
tests/client_test.php               request shaping + log sanitisation
tests/sync_status_test.php          discovery + transitions
tests/claimable_test.php            claimable detection + cache
tests/privacy_provider_test.php     export + delete
pix/icon.svg                        plugin icon
thirdpartylibs.xml                  none
deploy.sh                           packaging (folder credentiumclaim/, exclude docs)
.github/workflows/deploy.yml        tag → release
.github/workflows/ci.yml            moodle-plugin-ci matrix (NEW)
README.md CHANGELOG.md DEPLOYMENT.md CLAUDE.md
```

---

### Task 1: Plugin skeleton — identity, capabilities, lang, DB schema
**Files:** `version.php`, `db/access.php`, `db/install.xml`, `db/upgrade.php`, `lang/en/local_credentiumclaim.php`, `pix/icon.svg`, `thirdpartylibs.xml`.
**Produces:** component name, capabilities (`local/credentiumclaim:manage|claim|viewreports`), table `local_credentiumclaim_status(id,userid,credentialkey,issuanceid,courseid,remotestatus,dismissed,timecreated,timemodified,timechecked)` with unique `(userid,credentialkey)` + index `(userid,remotestatus,dismissed)`, base lang keys.
- [ ] version.php per Global Constraints, with `$plugin->dependencies`.
- [ ] access.php: manage(RISK_CONFIG/system/manager), claim(archetype user allow), viewreports(RISK_PERSONAL/system/manager).
- [ ] install.xml: fields + keys + indexes exactly as spec §4.4.
- [ ] upgrade.php: `function xmldb_local_credentiumclaim_upgrade($oldversion) { return true; }`.
- [ ] lang en: pluginname, privacy metadata, settings keys, banner strings, claim action strings, task name, errors, capabilities.
- [ ] Commit `feat: plugin skeleton (identity, capabilities, schema, lang)`.

### Task 2: API client + tests
**Files:** `classes/api/client.php`, `tests/client_test.php`.
**Interfaces — Produces:**
- `client::__construct(?string $apiurl=null, ?string $apikey=null)` (falls back to config).
- `client::is_configured(): bool`
- `client::get_status_batch(array $ids): array` → `[id => (object){status,credentialid?,issuedat?,claimedat?}]`, chunked ≤500.
- `client::get_claim_link(string $id, string $locale): \stdClass` → `{actiontype, claimurl, expiresat}`.
- `client::get_templates(): array`.
- `client::sanitize_for_log($body): string` (redacts claimUrl/apikey).
- protected `request($method,$endpoint,$params)` — override point for test transport (mock subclass).
- [ ] Test: `get_status_batch` builds POST body `{issueRequestIds:[...]}`, splits >500, maps by id.
- [ ] Test: `get_claim_link` posts `{locale}` to `/api/credential-issue-requests/{id}/claim-link`, id url-escaped, maps actionType.
- [ ] Test: `sanitize_for_log` removes any `claimUrl` value and the api key.
- [ ] Test: non-2xx → `moodle_exception('apierror',...)`, message contains no secret.
- [ ] Implement client using `\curl` (mockable via protected `raw_request`), header `API-KEY`.
- [ ] Commit `feat: Credentium v2 API client with log sanitisation`.

### Task 3: Claimable service + cache + lib helpers
**Files:** `classes/local/claimable.php`, `db/caches.php`, `lib.php`.
**Interfaces — Produces:**
- `claimable::count_for_user(int $userid): int` (cached; banner-worthy = remotestatus='issued' AND dismissed=0).
- `claimable::list_for_user(int $userid): array` (rows).
- `claimable::purge_cache(int $userid): void`.
- `claimable::dismiss(int $userid, int $rowid): void` / `dismiss_all(int $userid)`.
- `claimable::mark_claimed(int $userid, string $credentialkey): void`.
- `local_credentiumclaim_log(string $msg): void` (secret-free error_log), `local_credentiumclaim_is_enabled(): bool`.
- `db/caches.php`: `$definitions['claimable'] = ['mode'=>cache_store::MODE_SESSION,'simplekeys'=>true]`.
- [ ] Test: count uses cache; purge invalidates; dismiss hides row; mark_claimed flips status + purges.
- [ ] Commit `feat: claimable service with session cache`.

### Task 4: Scheduled task (discover + poll) + tests
**Files:** `classes/task/sync_status.php`, `db/tasks.php`, `tests/sync_status_test.php`.
**Interfaces — Consumes:** `client`, `claimable`. **Produces:** task class `\local_credentiumclaim\task\sync_status`.
- discover: read `local_credentium_issuances` (status='issued', credentialid NOT NULL) → upsert rows (seed remotestatus='issued'); dedupe on (userid,credentialkey).
- poll: gather non-terminal credentialkeys, chunk 500, `get_status_batch`, update remotestatus/timechecked, purge cache on issued→claimed.
- inject a client via a settable property for tests.
- [ ] Test: seeding sibling table then running task creates rows.
- [ ] Test: batch response 'claimed' updates row + purges cache.
- [ ] db/tasks.php: default `*/15` minute-of-... (every 15 min).
- [ ] Commit `feat: sync_status scheduled task`.

### Task 5: Banner hook + renderable + template
**Files:** `classes/local/hook_callbacks.php`, `classes/output/banner.php`, `templates/banner.mustache`, `db/hooks.php`.
**Interfaces — Consumes:** `claimable`. Guard: logged-in, not guest, has `:claim`, enabled, showbanner, count>0.
- Verify hook class `\core\hook\output\before_standard_top_of_body_html_generation` exists (class_exists guard) — if absent, no-op.
- banner renderable → mustache: dismissible notice + "Claim now" → `mycredentials.php` + dismiss form (sesskey).
- [ ] Commit `feat: unclaimed-credential banner via Hooks API`.

### Task 6: User pages + claim flow + nav
**Files:** `mycredentials.php`, `claim.php`, nav callback in `lib.php`, dismiss handling.
- `mycredentials.php`: require_login + `:claim`; list `claimable::list_for_user`; per-row claim button (POST to claim.php with sesskey + rowid).
- `claim.php`: require_login + require_sesskey; load row, assert `userid==$USER->id`; `client::get_claim_link`; branch actiontype; open claimUrl new tab (interstitial page, `rel=noopener`, `no-store`); already_claimed→mark_claimed; not_ready/paused→message. Never log claimUrl.
- nav node → mycredentials (idiomatic 4.5/5.0 callback; verify).
- [ ] Commit `feat: my-credentials page and claim flow`.

### Task 7: Settings, admin form, test connection, admin report
**Files:** `settings.php`, `classes/form/admin_settings_form.php`, `admin_settings.php`, `testconnection.php`, `index.php`.
- settings.php: `admin_externalpage('local_credentiumclaim', pluginname, admin_settings.php, 'moodle/site:config')` + report page under Reports (`:viewreports`).
- form fields: enabled, apiurl(PARAM_URL), apikey(passwordunmask), showbanner, debuglog; save via set_config + purge config cache; Test Connection button (enabled when url+key set).
- testconnection.php: sesskey; `client::get_templates()`; friendly OK/error.
- index.php: viewreports; counts by remotestatus.
- [ ] Commit `feat: admin settings, test connection, status report`.

### Task 8: Privacy provider + user_deleted observer + tests
**Files:** `classes/privacy/provider.php`, `classes/event/observer.php`, `db/events.php`, `tests/privacy_provider_test.php`.
- full metadata provider (DB table + external `credentium_api`); export/delete.
- observer deletes user rows on `\core\event\user_deleted`.
- [ ] Commit `feat: privacy provider and GDPR cleanup`.

### Task 9: Polish lang, docs
**Files:** `lang/pl/local_credentiumclaim.php`, `README.md`, `CHANGELOG.md`, `DEPLOYMENT.md`, `CLAUDE.md`.
- pl mirrors en; docs mirror sibling structure; CHANGELOG 1.0.0.
- [ ] Commit `docs: Polish translation and documentation`.

### Task 10: Release pipeline + CI
**Files:** `deploy.sh`, `.github/workflows/deploy.yml`, `.github/workflows/ci.yml`.
- deploy.sh: replica; ZIP folder `credentiumclaim/`; critical files list `version.php,lib.php,settings.php,db/install.xml,db/access.php,lang/en/local_credentiumclaim.php`; add `--exclude='docs'` and `--exclude='.claude'`.
- deploy.yml: replica; rename name/body; artifact name credentiumclaim-plugin.
- ci.yml: moodle-plugin-ci v4, matrix Moodle MOODLE_405_STABLE (PHP 8.1/8.2) + MOODLE_500_STABLE (PHP 8.2/8.3), pgsql; steps: phplint, phpmd, codechecker, validate, savepoints, mustache, phpdoc, phpunit. Install sibling `local_credentium` as extra plugin (dependency) so validate/phpunit resolve the dependency.
- [ ] Commit `ci: release pipeline (identical) + moodle-plugin-ci`.

---

## Self-review notes
- Spec coverage: all §4 components mapped to tasks 1–10. Release §4.13 → Task 10. Privacy §4.10 → Task 8. Banner §4.7 → Task 5.
- Dependency subtlety: CI must install `local_credentium` as an extra plugin for `validate`/`phpunit` to satisfy `$plugin->dependencies`; noted in Task 10.
- Risk: nav callback + hook class names differ across 4.5/5.0 → `class_exists`/capability guards; CI matrix on both versions catches it.
- No local PHP → red/green is validated in CI, not locally; tests still authored per task.
