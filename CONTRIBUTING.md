# Contributing

Contributions are welcome once the repository is published and its licensing/ownership review has been completed.

## Development setup

Install Python 3.11+ with Tkinter, create a virtual environment and run:

```sh
python -m pip install -e '.[desktop]'
python -m unittest discover -s tests -v
python tools/check_publication.py
```

Keep changes small and include regression tests. Do not replace the user's local profile, send real API requests, or write to any live Kimai instance during unit tests. Tests must use fixtures with invented identities, domains and credentials.

## Security and privacy boundaries

- Do **not** commit credentials, personal time records, browser histories, meeting titles, raw URLs, chat bodies, Telegram session files, or SQLite ledgers.
- Do **not** infer human working hours from an open browser, a calendar invitation, automated AI processing, or a long period without mouse activity.
- Default operation must remain read-only. A Kimai mutation requires explicit user opt-in, scoped credentials, strict preflight checks, idempotent outbox state and verification by GET.
- Treat a failed or uncertain HTTP write as an unresolved operation: never blindly retry a POST.
- Test all date-boundary, timezone, overlap and concurrent-run changes.
- Do not introduce hard-coded organization URLs, project IDs or private paths.

## Pull requests

Explain the behavior before and after your change; link any issue if available. Include tests and a brief statement of user-visible impact. Run the unit suite locally, but do not claim Windows/macOS native support based only on Linux tests. For security-sensitive fixes use the private vulnerability route in [SECURITY.md](SECURITY.md).

## Licenses and credits

The proposed project license is GPL-3.0-or-later. Submissions must be your own work or material you have permission to distribute under a compatible license. Preserve origin/attribution and review the licensing of any copied code before public release. See [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).
