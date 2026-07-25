# Credentium® Claim (`local_credentiumclaim`)

> ## ⚠️ DEPRECATED — functionality absorbed into Credentium® Integration ≥ 3.0.0
>
> As of **2026-07-25** this plugin is no longer developed or supported. Its entire
> functionality (claim status tracking, learner reminders, the "My credentials"
> page and one-click claiming) is **built into the
> [Credentium® Integration](https://github.com/cloudteampl/moodle-local_credentium)
> plugin from version 3.0.0**, which is the single plugin to install going forward.
>
> **Migration is automatic:** upgrade `local_credentium` to ≥ 3.0.0 on a site that
> runs this plugin — the upgrade migrates all tracking data (claim statuses,
> banner dismissals, check timestamps; nobody gets re-notified) and this plugin's
> settings, then disables this plugin. Afterwards **uninstall
> `local_credentiumclaim`** under Site administration → Plugins → Plugins overview.
> Only the status check interval must be re-selected if you had customised it.
>
> This repository is archived and kept read-only for reference. Please report any
> issues against
> [`moodle-local_credentium`](https://github.com/cloudteampl/moodle-local_credentium/issues).

A Moodle **local** plugin that tells learners, inside the Moodle UI, when they
have an issued-but-unclaimed Credentium® digital credential — and lets them claim
it in a couple of clicks.

It is a companion to the [`local_credentium`](https://github.com/cloudteampl/moodle-local_credentium)
plugin (**Credentium® Integration**), which issues the credentials. This plugin
does **not** issue anything; it watches the issuance records, checks their claim
status with Credentium®, and nudges the learner to collect what is theirs.

It also **reuses that plugin's API endpoint and key** — there is no second place
to configure credentials, and no second key to rotate.

- **Copyright** © 2025 CloudTeam Sp. z o.o.
- **Licence:** GNU GPL v3 or later.
- **Credentium®** is a registered trademark. Credentium is a paid third-party
  service; this plugin is free software and does not include a Credentium
  subscription.

## Compatibility

| | |
|---|---|
| Moodle | 4.5 – 5.0 (`requires` 4.5, `supported` [405, 500]) |
| PHP | 8.1+ |
| Depends on | `local_credentium` (hard dependency) |

## How it works

```
local_credentium                 local_credentiumclaim
(issues credentials)             (this plugin)
        │
        │ writes issuance rows
        ▼
{local_credentium_issuances} ──▶ scheduled task (interval configurable)
                                   1. discovers issued credentials
                                   2. batch-polls Credentium for status
                                   3. stores status locally
                                         │
     every page ── hook ──────────▶ cheap cached lookup
                                         │  unclaimed? → banner
                                         ▼
                                   "My credentials" → Claim
                                         │ POST (new tab)
                                         ▼
                                   single-use claim link → Credentium Wallet
```

1. **Discovery & status sync.** A scheduled task (`sync_status`, every 15 minutes
   by default) reads issued credentials from `local_credentium`'s issuance table,
   then batch-queries the Credentium® API for each credential's claim status
   (`processing` / `issued` / `claimed` / `failed`). Results are cached locally so
   the rest of the plugin never has to call the API on a page load.
2. **Learner is told three ways** (a dismissible banner alone was too easy to
   miss). The moment a credential becomes `issued`:
   - a **bell notification** (popup + email) is sent once — the canonical Moodle
     "you have something" surface;
   - a persistent **My credentials (N)** entry appears in the user menu (avatar
     dropdown) and does **not** vanish when the banner is dismissed;
   - a dismissible **banner** appears at the top of every page.
   All three are backed by one cached, indexed lookup, so a page load never calls
   the API. The banner honours dismissal; the menu entry and the profile node
   (both persistent pointers) share a dismiss-independent count.
3. **Claiming.** When the learner clicks *Claim*, the plugin lazily asks
   Credentium for a **single-use claim link** and opens it in a new tab. The link
   either creates a Credentium Wallet account or logs the learner in, then lands
   on the credential. Once claimed, the next status sync clears the reminder.
4. **Afterwards.** A claimed credential stays on "My credentials" with a **View in
   wallet** button, so the page is a record of what a learner has earned rather
   than a to-do list that empties itself. That button uses the same mechanism as
   *Claim* — the plugin asks the API for a link and opens the `claimUrl` it returns
   — so it lands the learner on the credential in their private wallet (a view, not
   a re-claim). The user-menu entry likewise stays put once the count reaches
   zero — it just drops the count.

## Security & privacy

- The claim URL is a bearer-equivalent secret. It is minted only on an explicit
  click, opened via a `target="_blank"` POST so it lives only in the new tab's
  redirect header, and is **never** rendered into HTML or written to a log.
- The API key is never stored by this plugin: it is read from `local_credentium`
  at call time, never rendered in page output, and redacted from logs.
- All state-changing endpoints require `require_login`, a capability check, and
  `sesskey`.
- A full Privacy API provider exports and deletes the learner's tracking data,
  and a `user_deleted` observer removes it automatically. Data sent to the
  Credentium API is declared as an external location.

## Configuration

Site administration → Plugins → Local plugins → **Credentium® Claim**:

| Setting | Meaning |
|---|---|
| Enable Credentium® Claim | Master switch. |
| API connection | **Read-only.** Endpoint and key inherited from the Credentium® Integration plugin. |
| Status check interval | How often the sync task runs (5 min – 4 h). Rewrites the task's cron schedule. |
| Show reminder banner | Whether to show the top-of-page banner. |
| Enable debug logging | Writes secret-free diagnostics to the server log. |

There is deliberately **no API URL or API key field here.** Both are inherited
from `local_credentium`, which this plugin already requires; duplicating them
would mean two places to rotate a secret and two ways to get it wrong. Edit them
under Site administration → Plugins → Local plugins → **Credentium® Integration**.

The connector stores its URL with an `/api` suffix (it calls
`{apiurl}/credential/issue`), while this plugin adds its own `/api/...` prefix.
The inherited URL is normalised accordingly, so a shared value cannot silently
produce `/api/api/...` 404s.

If the connector runs in **category mode**, each tracked credential is checked
using the credentials that apply to its course, and the sync issues one batch
call per distinct key.

### Wallet links come from the API, never from this plugin

The plugin never composes a wallet URL. Every link into the wallet — for *Claim*
and for *View in wallet* alike — is obtained by asking the API for a claim link
(`POST /api/credential-issue-requests/{id}/claim-link`) and using the `claimUrl`
it returns, unchanged. There is nothing to configure: no wallet address is stored
or guessed. The link is minted at the moment of the click and only ever travels in
the redirect header, so an issued credential's single-use claim URL is never
written into a page.

Earlier releases (1.5.0–1.5.1) tried to build a wallet URL from a stored address;
that was the wrong approach and is gone. The API returns a login-gated deep link
for already-claimed credentials too, so *View in wallet* lands the learner on the
credential in their private wallet — a view, not a re-claim.

### Required API key scopes

Credentium® API keys are scoped per capability. The issuing plugin only ever needs
`templates:read` and `credentials:issue`; this plugin polls statuses and mints
claim links, which require **`credentials:read`**.

Because the key is now inherited, the connector's key must carry all three scopes.
If you previously gave this plugin its own narrowly-scoped key, widen the
connector's key in Credentium® (Organisation Settings → API Keys) before or right
after upgrading — otherwise status checks return HTTP 401.

**Test connection** probes both scopes and says explicitly which one is missing,
and the report surfaces the same reason (including the `required_scope` returned
by the API) rather than a bare "HTTP 401".

### Report and diagnostics

Site administration → Reports → **Credentium® Claim status** shows the status
breakdown plus a diagnostics table: the inherited endpoint, the schedule, when
the task last ran and next runs, and the outcome of the last run. Administrators
also get a **Check status now** button, so a change can be verified without
waiting for cron.

If Credentium® does not recognise some tracked identifiers, the report says so
explicitly — that is the signature of an API key belonging to a different
organisation than the one that issued the credentials. Credentials the API simply
failed to answer for are counted separately and reported as still pending, so a
service outage is never mistaken for that.

When a run fails, the report classifies the failure and gives advice specific to
it, because the five kinds need five different responses:

| Kind | What it means | What to do |
|---|---|---|
| Authentication | The key was refused (`401`/`403`) | Check the connector's key and its `credentials:read` scope |
| Request | Credentium® rejected the request (other `4xx`) | Look for a plugin update; quote the technical detail to support |
| Busy | Rate-limited, or timed out answering (`408`/`429`) | Usually nothing; if it is constant, lengthen the status check interval |
| Service | Credentium® failed to complete it (`5xx`) | Nothing — the plugin retries and catches up by itself; escalate only if it persists |
| Network | The API was never reached | Check outbound HTTPS, proxy, firewall and DNS |

The raw failure (status, endpoint and how many attempts were made) stays in the
diagnostics table, because that is what a support ticket needs.

### Resilience

Transient failures — a dropped connection, a read timeout, `408`, `429` or any
`5xx` — are retried, for a total of three attempts, backing off exponentially
between them, honouring `Retry-After` when the service sends one and capping any
single wait at eight seconds. Deterministic refusals (the other `4xx`) are never
retried.

Retrying is bounded twice over: once the service or the route to it has failed,
the remaining credential groups in the same run stop retrying, and the polling
phase as a whole runs under a wall-clock budget. That budget is enforced inside
the API client — per attempt and per batch chunk, not merely once per group — so
no call is started that the remaining time cannot finish, and the bound does not
have to be re-derived whenever a retry constant changes. A site running the
connector in category mode (one API key per category, so one batch call per key)
therefore cannot stretch a single run past the time limit a manual **Check status
now** is given. Whatever the budget cuts short keeps its older `timechecked` and
is first in the queue on the next run, and is reported as still pending.

Retrying belongs to the scheduled task only: the page-load refresh a learner
triggers makes exactly one attempt and otherwise falls back to the last known
statuses, so a struggling API can never slow down a page render more than once.

> **Note on the API surface.** Status checks and claim links use the Credentium®
> **v2** endpoints (`/api/credential-issue-requests/statuses` and
> `/api/credential-issue-requests/{id}/claim-link`), keyed by `issueRequestId`.
> The legacy `POST /api/credential/issue` endpoint used by `local_credentium`
> returns exactly that `issueRequestId`, so the identifier stored in
> `{local_credentium_issuances}.credentialid` is the correct key for both.

## Capabilities

| Capability | Default | Purpose |
|---|---|---|
| `local/credentiumclaim:claim` | authenticated user | See the banner and claim own credentials. |
| `local/credentiumclaim:manage` | manager | Configure the plugin. |
| `local/credentiumclaim:viewreports` | manager | View the status report. |

## Installation

1. Download the ZIP from the [Releases](https://github.com/cloudteampl/moodle-local_credentiumclaim/releases) page.
2. Site administration → Plugins → Install plugins → upload the ZIP, **or** extract
   it to `{moodle_root}/local/credentiumclaim/`.
3. Visit Site administration → Notifications to complete installation.
4. Make sure the API URL and key are configured in **Credentium® Integration**,
   then enable this plugin. It has no credentials of its own to fill in.

`local_credentium` must be installed first (it is a hard dependency).

## Scheduled task

`\local_credentiumclaim\task\sync_status` runs every 15 minutes by default.
Change the interval in the plugin settings (**Status check interval**), or edit
the cron expression directly under Site administration → Server → Scheduled
tasks — a hand-edited schedule is reported as *Custom* and left alone.

## Support

Commercial support and Credentium® subscriptions: CloudTeam Sp. z o.o.

## Licence

GNU GPL v3 or later. See `LICENSE`.
