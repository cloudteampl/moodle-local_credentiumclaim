# Design Spec — `local_credentiumclaim` ("Credentium Claim")

- **Date:** 2026-07-06
- **Status:** Approved (design), pending implementation
- **Author:** CloudTeam / Claude
- **Frankenstyle component:** `local_credentiumclaim`
- **GitHub repo:** `cloudteampl/moodle-local_credentiumclaim`
- **Sibling plugin (reused conventions):** `local_credentium` (`cloudteampl/moodle-local_credentium`)
- **Reference integration (API contract):** `ct-aiheroes-portal` (`CredentiumHttpAdapter.cs`)

---

## 1. Purpose & scope

A new Moodle `local` plugin that **proactively tells a logged-in Moodle user, inside the Moodle UI, that they have an issued-but-unclaimed Credentium digital credential and lets them claim it**.

The plugin does **not** issue credentials — that remains the job of the existing `local_credentium` plugin. This plugin:

1. Discovers which users have issued credentials (from `local_credentium`'s issuance records).
2. Periodically checks, via the Credentium **v2** API, whether each credential is still *issued* (unclaimed) or has become *claimed*.
3. Shows a dismissible banner on every page (and a persistent "My credentials" entry point) to users who have an unclaimed credential.
4. On user click, lazily mints a **per-user single-use claim link** from Credentium and opens it (creates a Credentium Wallet account or logs the user in, then lands on the credential).

### Out of scope
- Issuing credentials (owned by `local_credentium`).
- Any admin-side bulk operations beyond a light status report.
- Modifying `local_credentium` (we depend on it read-only).

### Compatibility
- Moodle **4.5 → 5.0** (`requires = 2023100900`, `supported = [405, 500]`), PHP 8.1+.
- Single code path for both versions via the **Hooks API** (available 4.4+); no version branching.

---

## 2. Key decisions (locked)

| # | Decision | Choice |
|---|---|---|
| 1 | UI surface | Dismissible top-of-page **banner** (global hook) + persistent **"My credentials" page** + navigation node. |
| 2 | Data source | **Hard dependency** on `local_credentium`; read its `local_credentium_issuances` table (read-only). No modification of the sibling. |
| 3 | Credentium API contract | Replicate AI Heroes **v2** endpoints. Build configurable + testable. Treat `local_credentium_issuances.credentialid` as the v2 `issueRequestId`; **document this assumption for verification with Credentium.** |

### Assumption to verify (decision 3)
`local_credentium_issuances.credentialid` is assumed equal to the Credentium v2 `issueRequestId` used by the status/claim endpoints. If the target Credentium instance disagrees, the only change needed is a mapping/capture layer (or a small additive change to `local_credentium` to store `issueRequestId`); the rest of this design is unaffected. Mitigations baked in: configurable base URL + endpoint paths, a **Test Connection** action, and clear (secret-free) error logging.

---

## 3. Architecture

```
                        ┌── scheduled task: sync_status (default every 15 min) ────────────┐
   local_credentium ───▶│ 1. read local_credentium_issuances WHERE status='issued'         │
   (issuance records)   │ 2. upsert candidates into local_credentiumclaim_status           │──▶ Credentium v2
                        │ 3. batch POST /api/credential-issue-requests/statuses (≤500/call) │    (API-KEY header)
                        │ 4. update remotestatus; on issued→claimed purge user cache        │
                        └──────────────────────────────────────────────────────────────────┘
                                              │  (local table + per-user session cache)
   every Moodle page                          ▼
   ─ hook before_standard_top_of_body_html ─▶ CHEAP lookup: does $USER have issued & !claimed & !dismissed?
                                              │ yes → render dismissible banner (Mustache)
                                              ▼ click
             claim.php (require_login, sesskey, ownership) ── POST /{id}/claim-link ──▶ Credentium v2
                                              │ {actionType, claimUrl(SECRET), expiresAt}
                                              ▼ open claimUrl in new tab (no-store, never logged)
```

**Performance principle:** the hook that runs on every page must be cheap — one indexed local read behind a per-user MUC session cache. All network I/O to Credentium happens in the scheduled task (batch) or lazily on user click (claim link). The hook never calls the API.

---

## 4. Components

### 4.1 `version.php`
```php
$plugin->component = 'local_credentiumclaim';
$plugin->version   = 2026070600;          // YYYYMMDDXX, single source of truth
$plugin->requires  = 2023100900;          // Moodle 4.5
$plugin->supported = [405, 500];          // 4.5 – 5.0
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0';
$plugin->dependencies = ['local_credentium' => ANY_VERSION];
```

### 4.2 Configuration — `settings.php` + `admin_settings.php`
Mirror the sibling's look: `settings.php` registers an `admin_externalpage` (`local/credentiumclaim:manage` / `moodle/site:config`) pointing at `admin_settings.php`, which renders a `moodleform` (`local_credentiumclaim_admin_settings_form`). A **Test Connection** button opens `testconnection.php`.

Config keys (`get_config('local_credentiumclaim', …)`):

| Key | Element | PARAM / handling | Default |
|---|---|---|---|
| `enabled` | checkbox | 0/1 | 0 |
| `apiurl` | text | `PARAM_URL`, `filter_var` URL check | '' |
| `apikey` | passwordunmask | `PARAM_RAW_TRIMMED` (key format `x.y`) | '' |
| `showbanner` | checkbox | 0/1 | 1 |
| `debuglog` | checkbox | 0/1 | 0 |

**Own `apiurl`/`apikey` (not reused from `local_credentium`)** because the sibling targets the *old* API surface (different host + no `/api` prefix); v2 status/claim live on the issuer host. Reusing config would point at the wrong endpoint. The API key is stored via `set_config` and masked in the UI (matching the sibling's global-key behaviour).

### 4.3 API client — `classes/api/client.php` (`\local_credentiumclaim\api\client`)
Moodle `\curl` wrapper (`$CFG->libdir/filelib.php`), header `API-KEY: <key>`, `Content-Type`/`Accept: application/json`, `CURLOPT_TIMEOUT = 30`. Base URL = `rtrim(apiurl,'/')`. Methods:

- `get_status_batch(string[] $issueRequestIds): array` → `POST /api/credential-issue-requests/statuses` with `{issueRequestIds: [...]}` (chunked ≤ **500**). Returns map `issueRequestId => {status, credentialId?, issuedAt?, claimedAt?}`. Reconcile by id, not index.
- `get_claim_link(string $issueRequestId, string $locale): object` → `POST /api/credential-issue-requests/{id}/claim-link` (id URL-escaped) with `{locale}` (allow-list `en`/`pl`, fallback `en`). Returns `{actionType, claimUrl, expiresAt}`.
- `get_templates(): array` → `GET /api/credential-template` (Test Connection only; lightweight auth probe).

**Security:** `claimUrl` and `apikey` are secrets. A `sanitize_for_log()` helper redacts any `claimUrl` before anything is written to a log (mirrors AI Heroes `SanitizeClaimLinkBody`). Non-2xx → `\moodle_exception('apierror', …)` with secret-free debug info. Client is constructor-injectable/mockable for unit tests.

Status vocabulary (raw Credentium → local): `processing` → keep `processing`; `issued` → `issued`; `claimed` → `claimed`; `failed` → `failed` (logged no-op, no auto-reissue). `actionType ∈ {create_wallet_and_claim, login_and_claim, already_claimed, not_ready}` (+ synthesized `paused`/`unknown`).

### 4.4 Database — `db/install.xml`
Table `local_credentiumclaim_status`:

| field | type | notes |
|---|---|---|
| `id` | int, PK autoinc | |
| `userid` | int, not null | FK → user; indexed |
| `credentialkey` | char(255), not null | the v2 `issueRequestId` (from sibling's `credentialid`) |
| `issuanceid` | int, null | source `local_credentium_issuances.id` (traceability) |
| `courseid` | int, null | FK → course (nullable) |
| `remotestatus` | char(20), default `processing` | processing/issued/claimed/failed/unknown |
| `dismissed` | int(1), default 0 | user dismissed banner for this credential |
| `timecreated` | int, not null | |
| `timemodified` | int, not null | |
| `timechecked` | int, default 0 | last status poll |

Indexes: **unique** `(userid, credentialkey)` (dedupe); `(userid, remotestatus, dismissed)` (fast hook lookup); `credentialkey`.

`db/upgrade.php` present (empty `xmldb_local_credentiumclaim_upgrade` returning true, ready for future savepoints).

### 4.5 Caches — `db/caches.php`
Session cache `claimable` (per-user), value = count of banner-worthy credentials. Read by the hook; purged by the sync task, dismiss action, and claim action for the affected user.

### 4.6 Scheduled task — `classes/task/sync_status.php`
Default schedule every 15 min (admin-editable). Steps:
1. Bail if `!enabled` or unconfigured.
2. **Discover:** read `local_credentium_issuances` where `status='issued'` AND `credentialid` is not null; upsert new candidates into `local_credentiumclaim_status` (seed `remotestatus='issued'`).
3. **Poll:** collect `credentialkey`s of rows not yet `claimed`/`failed`; chunk ≤500; call `get_status_batch`; update `remotestatus`, `timechecked`. On `issued → claimed`, purge that user's `claimable` cache.
4. **Cleanup:** optionally prune `claimed` rows older than a retention window; prune rows whose source issuance vanished.

Uses `mtrace`, respects Moodle's task locking. Reading the sibling table is the chosen coupling (decision 2); a hard `dependencies` entry guarantees the table exists.

### 4.7 UI hook — banner
`classes/local/hook_callbacks.php` → callback for `\core\hook\output\before_standard_top_of_body_html_generation`, registered in `db/hooks.php`. Guards: `isloggedin() && !isguestuser()`, `has_capability('local/credentiumclaim:claim', …)`, `enabled`, `showbanner`. On a positive cheap cache lookup, renders `templates/banner.mustache` via `classes/output/banner.php` (renderable + templatable) using `\core\output\notification` styling; includes a "Claim now" button (→ `mycredentials.php`) and a dismiss control. `$hook->add_html(...)`.

> Implementation note: confirm the exact hook class name resolves on both 4.5 and 5.0 at build time; if a version lacks it, degrade gracefully (banner simply not injected — the "My credentials" page and nav node still work).

### 4.8 User pages
- `mycredentials.php` — `require_login`; lists the user's unclaimed credentials with per-credential "Claim" buttons; the banner and nav node link here. Persistent entry point.
- `claim.php` — `require_login` + `require_sesskey`; verifies the credential row belongs to `$USER`; calls `get_claim_link`; branches on `actionType` (create/login → open `claimUrl` in a new tab via a small interstitial with `rel="noopener noreferrer"` and a manual fallback link; `already_claimed` → mark claimed locally + message; `not_ready`/`paused`/`unknown` → informative message). Response sends `Cache-Control: no-store`; `claimUrl` is never logged or persisted.
- Dismiss handled by a POSTed action (sesskey) that sets `dismissed=1` for a credential (or all) and purges the cache.

Navigation node added via the most idiomatic callback that works on 4.5 + 5.0 (chosen at build time), pointing at `mycredentials.php`.

### 4.9 Capabilities — `db/access.php`
- `local/credentiumclaim:manage` — RISK_CONFIG, system, `manager` default.
- `local/credentiumclaim:claim` — archetype `user` default allow; gates banner + claim of own credentials.
- `local/credentiumclaim:viewreports` — RISK_PERSONAL, system, `manager`; light admin status report (`index.php`).

### 4.10 Privacy — `classes/privacy/provider.php`
Full metadata provider: declares the `local_credentiumclaim_status` DB table (userid, credentialkey, courseid, remotestatus, timestamps) and an external location `credentium_api` (issueRequestId + locale sent; claim URL received, not stored). Implements `get_contexts_for_userid`, `get_users_in_context`, `export_user_data`, `delete_data_for_*`. `db/events.php` observer for `\core\event\user_deleted` deletes the user's rows.

### 4.11 Language — `lang/en/local_credentiumclaim.php` + `lang/pl/…`
Full en + near-complete pl, sibling key conventions (`_desc`/`_help`, `error:*`, `status_*`, `notification:*`, `testconnection_*`, `privacy:metadata:*`).

### 4.12 Tests — `tests/`
PHPUnit (`advanced_testcase`), mockable client (no network):
- `sync_status_test.php` — discovery from a seeded sibling table + status transitions.
- `hook_callbacks_test.php` — claimable detection / cache behaviour / guards.
- `privacy_provider_test.php` — export + delete.
- `client_test.php` — request shaping, log sanitisation of `claimUrl`, error mapping (with a stubbed transport).

### 4.13 Release pipeline (identical to sibling) + CI (best-practice add-on)
- `deploy.sh` — replica; ZIP top-level dir `credentiumclaim/`, critical-file list updated, **`--exclude='docs'`** added (dev specs must not ship). Produces `local_credentiumclaim-<release>.zip` + `.sha256` + `.md5`.
- `.github/workflows/deploy.yml` — replica; tag `v*` → read `$plugin->release` → verify tag matches → `deploy.sh` → upload artifacts → GitHub Release (installable ZIP). Renamed name/body.
- `.github/workflows/ci.yml` — **new**: `moodle-plugin-ci` matrix (Moodle 4.5 + 5.0, supported PHP/DB) running codechecker, phplint, phpunit, mustache/grunt/validate. This is the real execution harness (no local PHP) and satisfies the best-practices requirement.
- `.gitignore` — replica.
- Docs: `README.md`, `CHANGELOG.md` (Keep-a-Changelog), `DEPLOYMENT.md`, `CLAUDE.md`.

---

## 5. Data flow summary (happy path)

1. `local_credentium` issues a credential → row in `local_credentium_issuances` (`status='issued'`, `credentialid=X`).
2. `sync_status` upserts a `local_credentiumclaim_status` row (`credentialkey=X`, `remotestatus='issued'`).
3. `sync_status` batch-polls Credentium; while unclaimed, `remotestatus` stays `issued`.
4. User loads any page → hook sees an unclaimed, non-dismissed credential → banner shown.
5. User clicks Claim → `claim.php` mints a claim link → new tab opens Credentium Wallet (create/login) → user claims.
6. Next `sync_status` sees `claimed` → row updated → cache purged → banner disappears.

---

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `credentialid` ≠ `issueRequestId` on target instance | Configurable client, Test Connection, secret-free error logs, documented assumption; isolated to a thin mapping layer if it must change. |
| Hook runs on every page (perf) | One indexed read behind a per-user session cache; zero API calls in the hook. |
| Claim URL is a bearer-equivalent secret | Never logged/persisted; `no-store`; minted lazily on click; opened via `rel=noopener`. |
| Hook class name differs across 4.5/5.0 | Verify at build; degrade to page/nav entry points if absent. |
| No local PHP to run tests | `moodle-plugin-ci` on GitHub Actions is the validation harness. |
| Prod base URL ambiguity (`.com`/`.dev`) | Base URL is admin-configured per install; no hardcoded default that could mislead. |

---

## 7. Definition of done
- Plugin installs cleanly on Moodle 4.5 and 5.0 with `local_credentium` present.
- Banner appears for users with unclaimed credentials; claim flow opens a working Credentium link; banner clears after claim.
- `moodle-plugin-ci` green (codechecker + phpunit) for both Moodle versions.
- Code review agent reports no significant issues.
- `deploy.sh`/`deploy.yml` produce an installable, checksummed ZIP via a `vX.Y.Z` tag; GitHub Release published.
- Repo `cloudteampl/moodle-local_credentiumclaim` created; `main` holds the release; local worktree + feature branch cleaned up.
