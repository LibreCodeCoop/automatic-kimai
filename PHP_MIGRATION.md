# Migrating an existing Automatic Kimai installation to PHP

This guide is for operators migrating an existing Python installation to the
PHP 8.4 / Laravel 12 / NativePHP implementation. It contains **no production
data**, customer identifiers, private directories, service names, or credentials.

## Principles

- The source repository does not contain private profiles, session files,
  snapshots, ledger databases, credentials or operational logs.
- Do not start a second Kimai writer while the old installation can post time.
- Import historical state with the authenticated account identity and a
  consistent private snapshot. Do not use copied data from another worker.
- A completed import is not evidence that all collectors behave identically.
- Work inferred from desktop or messaging activity is not independently verified
  billable time. Reconcile by GET and preserve uncertain outcomes.

## Operator checklist

1. Install PHP dependencies and create an installation-specific encryption key.
   Never reuse a developer's local `APP_KEY` in any desktop build.
2. Connect the worker's individual Kimai token, check account identity, timezone,
   project and activity catalog, and confirm consent for every optional source.
3. Run the PHP application in read-only mode and compare complete-day evidence
   with the old installation. Check source freshness and crash recovery.
4. In a private maintenance window, stop **and disable** legacy timers and
   long-running workers. Keep a verified backup offline.
5. Create consistent snapshots with
   `php scripts/snapshot-legacy-state.php --read-only-snapshot <private-cache>`.
   Transfer the history only with `php artisan automatic-kimai:import-legacy
   --legacy-cache=<private-cache> --apply-import`.
6. Run `php artisan automatic-kimai:migration-audit
   --legacy-cache=<private-cache> --strict`. Resolve every blocker.
   Confirm no uncertain remote publication outcomes remain.
7. Verify the private cutover authorization and the single account-bound PHP
   writer. Validate against a **disposable** Kimai account before production.
8. Activate PHP timers only after source and publication equivalence,
   restart behavior, monitoring and rollback are checked. Never write while the
   legacy publisher remains active or enabled.

These steps are operational, not triggered by merging code. The installer
and cross-platform package testing have separate release gates.

## Troubleshooting

- A read-only PHP cycle may be healthy without permission to publish.
- The `automatic-kimai:publish-approved` command is a dry run unless its
  explicit post option and independent cutover controls are satisfied.
- Telegram MTProto needs a PHP runtime with GMP for acceptable performance.
  A developer Docker image is not an end-user distribution.
- Historical differences in classification need evidence, not invented approvals.
- Local AI classifications are suggestions, not work-time measurements.

Consult `README.md`, `php-app/README.md`, `PRIVACY.md`, `SECURITY.md`, and
`RELEASE_CHECKLIST.md` for public development and release requirements.
