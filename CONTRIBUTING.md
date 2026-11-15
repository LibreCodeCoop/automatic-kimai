# Contributing

Contributions to Automatic Kimai are welcome after the source publication
and licensing review has been completed. The application is PHP 8.4+,
Laravel 12 and NativePHP/Electron; **the Python pilot is not part of the
new product source tree**. Its original implementation remains a historical
reference for migration tests, but a numerical difference alone does not
prove an error in the PHP algorithm.

## Development and tests

Prepare PHP 8.4+, Composer 2 and Node 22+ (for desktop preview):

~~~sh
cd php-app
composer install --no-interaction
cp .env.example .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php scripts/check-publication.php
node --test desktop-preview/*.test.cjs
~~~

Do not run the unit suite against an actual user's private profile or
Kimai account. Tests must use a temporary LocalStore, isolated SQLite
files and fake HTTP transports with invented identities and data.
Do not use the development backend or production token for publication tests.

## Source control and privacy

- Never commit real tokens, private domains, chats, raw URLs, private
  SQLite ledgers, API sessions, browser history or transcripts.
- Protect all private files with owner-only permissions, and never
  print bearer credentials, response bodies or personal content in logs.
- Maintain .publication-denylist locally (0600) to catch names/domains
  unique to your own pilot before publication.
- Use only trusted opt-in source data. Mark AFK and missing watchers as
  unknown, not automatically worked time.
- Do not infer hours from calendar entries or agent/voice execution.
- Mutation requires frozen human approval, account/catalog preflight,
  an idempotent outbox, no overlapping timesheet and exact GET verification.
- Treat HTTP timeouts and process crashes as **unknown**, never retrying
  POST or PATCH without reconciliation.
- Test timezone, midnight, partial overlap, duplicate and concurrent
  execution cases.
- Avoid hardcoded organization names, instance URLs or customer IDs.

## Pull requests

Describe user-visible behavior, security and privacy boundaries, test
evidence and known limitations. Keep changes focused and add regression
tests. Local Linux success does not establish Windows/macOS NativePHP
support. The PR must remain draft until the release checklist and
cutover gates are satisfied; a CI billing failure is not a test pass.

See [SECURITY.md](SECURITY.md), [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md),
[PHP_MIGRATION.md](PHP_MIGRATION.md) and the proposed GPL-3.0-or-later
license in [LICENSE](LICENSE).
