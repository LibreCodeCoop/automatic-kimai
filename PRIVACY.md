# Privacy and data handling

Automatic Kimai runs on the user's device. It compares **sanitized** activity metadata with the user's own Kimai timesheets.

## Collection and processing

- **ActivityWatch** records application/window activity and idle status on the user's local device. ActivityWatch itself may retain original window titles and browser metadata. Automatic Kimai reads a reduced, sanitized subset; it does not include raw personal URLs, message bodies or full private window titles in its own cache.
- **Kimai** is contacted via the instance URL supplied by the user, using the user's own API token. Timesheets are read for the authenticated user within bounded date ranges. Only explicitly enabled modes can send non-billable time entries.
- **Nextcloud Calendar/Talk**, **GitHub** and **Telegram** are separate, optional integrations. Calendar summaries, message bodies and conversation titles are not retained in Automatic Kimai's regular caches. Telegram stores hashed dialog identifiers and local session material in the protected user directory. A hash is **not** equivalent to anonymization.
- **ChatGPT and Claude** integrations interpret limited browser-category metadata when enabled. They do not read private conversation history through an OpenAI/Anthropic account API.
- **Manual entries** include the user's own description and confirmed times; those details are transmitted to Kimai when the user submits them.

## Where information is stored

Profiles, sanitized ActivityWatch data and SQLite reconciliation ledgers are stored under the user's local application directories. Secrets are stored in the operating system keyring where supported; Linux may use a file restricted to its owner for compatibility with the existing implementation. **Do not copy those files into the source repository or attach them to bug reports.**

The local cache defaults to a recent lookback period. Existing private files and the underlying ActivityWatch database are not automatically deleted when the program closes. No automatic telemetry or cloud analytics are built into this project.

## Consent and limitations

Automatic publication is disabled by default, remains non-billable if enabled, and never constitutes payment approval. The user selects which optional sources may be consulted. Merely scheduling a calendar event does not verify attendance; merely having an AI browser window open does not verify human work.

The codebase does not currently ship a complete end-user data export/delete wizard or a validated uninstall process. **Do not claim compliance with a specific privacy law based on these design safeguards alone**; deployments must assess local labor, privacy and data-protection requirements separately.
