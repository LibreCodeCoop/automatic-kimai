# Verification plan — Automatic Kimai alpha

This plan applies to **any user, organization, Kimai instance or operating system**. All automated fixtures must use invented names, URLs and credentials. Tests never send data to a live account.

## Seven-day local evaluation

| Day | Scenario | Acceptance criteria |
| --- | --- | --- |
| 1 | Install and connect | Individual Kimai account verified, no hard-coded customer/project; default remains read-only |
| 2 | Local activity detection | AFK, screen locks and private windows do not silently become work hours |
| 3 | Restart/offline | Collectors recover persisted ActivityWatch evidence; no time invented during shutdown |
| 4 | Fragmented activities | Pauses and unverified context generate review items, not automatic labor |
| 5 | Reconciliation | Existing entry extended only with PATCH/GET under idempotent outbox; never blindly repeat POST |
| 6 | Optional integrations | Each source has explicit opt-in, scoped permissions and sanitized metadata |
| 7 | Review against actual work | Compare proposed times, breaks, manual reports and calendar commitments; leave remuneration approval out of scope |

## Mandatory regression scenarios

- Midnight transition: entry observed through the final second can end exactly 00:00 next day. Any further interval must be evaluated separately.
- Interrupted POST: ambiguous remote response must be reconciled by GET before retry is even considered.
- Worker isolation: cache, credentials, SQLite ledger and profile cannot be reused across identities.
- Computer on, person away: no auto work attribution; AFK is not billable labor.
- Personal or declined calendar event: no automatic claim of presence; schedule-only alerts can be reviewed.
- A scheduled work event with no timesheet in a day that contains other entries: report uncovered intervals without inventing hours.
- Work away from the computer: explicit worker declaration, pauses and date bounds must be checked; time from a schedule is labeled estimated.
- ChatGPT or Claude browser tab with no independent evidence: remains review-only.
- No ActivityWatch data: do not erase valid prior snapshot or publish fabricated entries.
- Remote time entry edited in Kimai: fail safely rather than overwriting another person's change.
- Credentials stored via OS keyring where supported; no secrets in publication artifacts.

## Release gates

**Source code can be reviewed and published once rights/attribution, secret scanning, CI and security reporting are cleared.** A native installer requires additional tests on real Windows, macOS and Linux machines, signing/notarization as relevant and a usable first-run assistant. Passing mocked platform tests is not equivalent to verifying a working installer.

See [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) for the gates and [GUIA_INICIO_COOPERADOS.md](GUIA_INICIO_COOPERADOS.md) for the Portuguese end-user procedure.
