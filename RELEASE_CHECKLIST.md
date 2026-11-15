# Publication, PR merge and release checklist

Automatic Kimai uses **PHP 8.4+, Laravel 12 and NativePHP/Electron**.
Source publication, merging the migration PR, switching the operating
runtime and releasing an end-user installer are **different decisions**.
A passing PHP unit suite does not prove operational cutover or billing safety.

## Source repository and code review

- [ ] Verify copyright ownership/permission for all source and third-party
  dependencies before first public GPL-3.0-or-later publication.
- [ ] Review licenses and security advisories for Laravel, NativePHP,
  Electron, MadelineProto and other shipped dependencies.
- [ ] Run the full PHP test suite, desktop-preview Node tests, package
  privacy guard and source check: php php-app/scripts/check-publication.php.
- [ ] Create a private, untracked .publication-denylist (mode 0600)
  containing any forbidden customer-specific domain/name and rerun the scan.
- [ ] Confirm no credential, session, encrypted secret, private SQLite,
  personal browsing record or other runtime state exists in Git or builds.
- [ ] Obtain an independent code review and working CI; do not waive
  a failing/missing check because a GitHub Actions billing limit blocks it.
- [ ] Avoid hardcoded organizations, customers, instance URLs and paths.
- [ ] Maintain a clear vulnerability reporting and maintainer contact.

## Additional blockers for PR #1 — Python to PHP migration

- [ ] Confirm every historical Python publication and human declaration is
  preserved with its original remote ID/provenance, without overwriting new
  PHP hypotheses. Run final catch-up from consistent snapshots **after
  freezing the Python writer**.
- [ ] Explain remaining algorithm differences by frozen evidence replay,
  proving AFK/private handling, breaks, attendance away from computer,
  AI agent time, calendar/Telegram and timezone boundaries.
- [ ] Validate Telegram MTProto and audio samples with explicit consent,
  limited/controlled requests and failure-safe continuation.
- [ ] Run approved-only POST against a disposable Kimai, introduce network
  faults, verify matching GET recovery and clean up temporary records.
- [ ] Wire PHP audio and agent telemetry to schedulers without guessing
  human labor from automated execution.
- [ ] Prove one writer at a time, reboot/startup continuity, rollback and
  monitoring before disabling all old Python timers.
- [ ] Validate local NativePHP bridge and source/build-time secret isolation
  without embedding a pilot user's credentials. Installation on Windows,
  Linux and macOS is explicitly **not** a prerequisite for merging this
  code migration; platform-specific install E2E occurs on demand.

**Do not enable PHP unattended posting or mark the PR ready for merge
until the applicable correctness, security, cutover and review gates
are evidenced.** Historical Python source files may be removed from the
PHP branch independently of stopping the original Python runtime.

## Additional end-user installer gates (on demand; not a PR merge blocker)

The desired delivery is a **single self-contained installation per OS**,
without requiring users to install Composer, PHP or Node separately.
NativePHP/Electron already configures these native packaging targets:
AppImage for Linux, NSIS .exe setup for Windows, and .dmg for macOS.
NativePHP's packaging step downloads/embeds a suitable PHP runtime.
These configurations are implemented but the actual installers have
**not** been verified on the three systems. Local Linux Electron preview
is not a substitute for a self-contained package.

- [ ] Bundle the correct PHP runtime and native dependencies per OS.
- [ ] Validate OS keyrings, encryption key creation, password handling,
  update/restart/uninstall and preserved local state.
- [ ] Exercise NativePHP/Electron installer, signatures/notarization and
  source provenance/checksums for each supported OS.
- [ ] Test ActivityWatch installation and permissions on real systems.
- [ ] Run usability tests with a nontechnical user, including recovery
  from missing integrations and offline work.

A source repository may exist as alpha while installer and operational
cutover are pending. Never present generated unsigned artifacts or
successful mocks as a verified consumer release.
