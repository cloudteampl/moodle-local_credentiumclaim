# Credentium® Claim (`local_credentiumclaim`)

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
organisation than the one that issued the credentials.

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
