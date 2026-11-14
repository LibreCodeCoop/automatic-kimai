# Automatic Kimai

**Status: 0.3.0 alpha, source-release candidate. Not a finished end-user installer.**

Automatic Kimai is an independent, local-first desktop project for reviewing work-activity evidence against an existing [Kimai](https://www.kimai.org/) timesheet account. It helps users identify missing work intervals and record confirmed work without interpreting every open browser tab, automated AI job or calendar invitation as human labor.

The project does **not** require a particular company, server, customer ID, project ID or domain. Users connect to **their own HTTPS Kimai instance** and select the appropriate client, project and activity through the application.

The source is being prepared under **GPL-3.0-or-later**; see [LICENSE](LICENSE). **Copyright ownership and permission to publish must be verified before the first public release.** No organization-specific pilot code, secret keys or personal caches are required.

## Features and boundaries

- Local ActivityWatch evidence with inactivity checks; per-user profiles, timezone and opt-in source settings.
- Read-only reconciliation of timesheets by default, with guarded, explicitly authorized internal preview submissions.
- Human-reviewed gaps, calendar schedule warnings, separate manual-entry and off-computer attendance forms.
- Optional Nextcloud Calendar/Talk, GitHub and Telegram metadata sources. No proprietary API or organization account is mandatory.
- SQLite outbox and GET verification protect against ambiguous HTTP writes and accidental duplicate timesheets.
- Windows, macOS and Linux scheduling templates, currently **not validated with real end-user installers**.

**Safety:** A calendar event or browser window is not evidence of hours actually worked. All automatic entries, when individually opted into, are non-billable previews. They do **not** approve payroll, invoices or customer billing. Manually confirmed work requires separate authorization. Read [PRIVACY.md](PRIVACY.md).

## Status of installation

The source and Python wheel are usable by developers for evaluation. There is **no validated point-and-click installer** for nontechnical end users yet. PyInstaller jobs are experimental and must not be presented as signed, verified release binaries.

For development on Python 3.11 or newer (with Tkinter available):

```sh
python3 -m venv .venv
. .venv/bin/activate
python -m pip install -e '.[desktop]'
python -m unittest discover -s tests -v
automatic-kimai
```

On Windows the virtual environment activation command is `.venv\Scripts\activate`; Tkinter and the OS credential backend must be available. [ActivityWatch](https://activitywatch.net/) must be installed and running locally, normally at `http://127.0.0.1:5600`.

For a new account, open the graphical setup and enter the URL of **your** Kimai server (for example `https://kimai.example.org`, an illustrative placeholder), your own Kimai API token, matching timezone and selected customer/project/activity. Leave automatic submissions **disabled** for the initial evaluation. Save settings, generate a read-only analysis and optionally install the 30-minute user-level scheduler.

For the Portuguese user walkthrough, see [GUIA_INICIO_COOPERADOS.md](GUIA_INICIO_COOPERADOS.md). It explicitly distinguishes currently implemented features from missing installer steps.

## Providers and storage

| Source | Purpose | Consent |
| --- | --- | --- |
| [ActivityWatch](https://activitywatch.net/) | Local window and inactivity evidence | Required for automatic classification |
| [Kimai](https://www.kimai.org/) | Personal account; timesheet GET and authorized writes | Required |
| Nextcloud Calendar/Talk | Scheduled intervals / own-action metadata | Disabled by default |
| GitHub | Account activity metadata | Disabled by default |
| Telegram | Selected dialog metadata, no saved bodies | Disabled by default |
| ChatGPT/Claude browser context | Website category only, not conversation history | Individually configurable |

Generated profile, token store, event cache and SQLite ledgers belong to each local OS user and must never enter source control. More details: [PRIVACY.md](PRIVACY.md).

## Development and release checks

```sh
python -m unittest discover -s tests -v
python -m pip install build
python -m build
python tools/check_publication.py
```

The source-based checks run on continuous integration. Read [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) and [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) before publishing. Experimental builds live in `.github/workflows/desktop-build.yml` and are unsigned.

The project is **not affiliated with the Kimai, Nextcloud, GitHub, Telegram, OpenAI or Anthropic maintainers**. Product names are used to identify optional integrations.
