# Credentium Claim (`local_credentiumclaim`)

A Moodle **local** plugin that tells learners, inside the Moodle UI, when they
have an issued-but-unclaimed Credentium digital credential — and lets them claim
it in a couple of clicks.

It is a companion to the [`local_credentium`](https://github.com/cloudteampl/moodle-local_credentium)
plugin, which issues the credentials. This plugin does **not** issue anything; it
watches the issuance records, checks their claim status with Credentium, and
nudges the learner to collect what is theirs.

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
{local_credentium_issuances} ──▶ scheduled task (every 15 min)
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

1. **Discovery & status sync.** A scheduled task (`sync_status`, every 15 minutes)
   reads issued credentials from `local_credentium`'s issuance table, then
   batch-queries the Credentium API for each credential's claim status
   (`processing` / `issued` / `claimed` / `failed`). Results are cached locally so
   the rest of the plugin never has to call the API on a page load.
2. **Reminder banner.** On every page, a lightweight hook does one cached,
   indexed lookup. If the learner has a credential that is `issued` but not yet
   claimed (and not dismissed), a dismissible banner appears at the top of the
   page. There is also a persistent **My credentials** entry on the user's
   profile.
3. **Claiming.** When the learner clicks *Claim*, the plugin lazily asks
   Credentium for a **single-use claim link** and opens it in a new tab. The link
   either creates a Credentium Wallet account or logs the learner in, then lands
   on the credential. Once claimed, the next status sync clears the reminder.

## Security & privacy

- The claim URL is a bearer-equivalent secret. It is minted only on an explicit
  click, opened via a `target="_blank"` POST so it lives only in the new tab's
  redirect header, and is **never** rendered into HTML or written to a log.
- The API key is stored server-side, masked in the UI, and redacted from logs.
- All state-changing endpoints require `require_login`, a capability check, and
  `sesskey`.
- A full Privacy API provider exports and deletes the learner's tracking data,
  and a `user_deleted` observer removes it automatically. Data sent to the
  Credentium API is declared as an external location.

## Configuration

Site administration → Plugins → Local plugins → **Credentium Claim**:

| Setting | Meaning |
|---|---|
| Enable Credentium Claim | Master switch. |
| Credentium API URL | Base URL of the Credentium **issuer** API (status + claim endpoints). |
| Credentium API key | Organisation API key (sent as the `API-KEY` header). |
| Show reminder banner | Whether to show the top-of-page banner. |
| Enable debug logging | Writes secret-free diagnostics to the server log. |

Use **Test connection** to verify the URL and key against
`GET /api/credential-template`.

> **Note on the API surface.** Status checks and claim links use the Credentium
> **v2** endpoints (`/api/credential-issue-requests/statuses` and
> `/api/credential-issue-requests/{id}/claim-link`), keyed by `issueRequestId`.
> This plugin treats the identifier stored by `local_credentium` as that
> `issueRequestId`. If your Credentium instance keys these endpoints differently,
> adjust the identifier mapping accordingly. See `CLAUDE.md`.

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
4. Configure the API URL and key, then enable the plugin.

`local_credentium` must be installed first (it is a hard dependency).

## Scheduled task

`\local_credentiumclaim\task\sync_status` runs every 15 minutes by default. Adjust
it under Site administration → Server → Scheduled tasks.

## Support

Commercial support and Credentium subscriptions: CloudTeam Sp. z o.o.

## Licence

GNU GPL v3 or later. See `LICENSE`.
