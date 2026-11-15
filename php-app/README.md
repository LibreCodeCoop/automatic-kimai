# Automatic Kimai — PHP application

This directory contains the PHP 8.4+ application, local Laravel interface and
NativePHP/Electron bridge. It does not include a deployed instance, a user
profile, a production Kimai token, or a preconfigured desktop distribution.

## Start development

Requirements: PHP 8.4+, Composer 2, SQLite, cURL, DOM, mbstring, OpenSSL,
fileinfo, XML and ZIP; Node 22+ for the Electron tooling. ActivityWatch is
optional at first launch and normally runs on the local loopback interface.

```sh
composer install --no-interaction
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php artisan serve --host=127.0.0.1 --port=6817
```

Connect **your own** Kimai account and enable individual sources in the local
interface. Never bind the application to a publicly accessible host. The example
environment disables debug output and mail logging and does not contain an
`APP_KEY`. Always generate a fresh key for each installation.

## Desktop development

```sh
php artisan native:install --force --publish
php artisan native:run
```

The Linux preview launcher under `desktop-preview/` requires a local PHP
backend; it is **not** an independently audited self-contained installer.
Cross-platform packaging requires isolated build directories, checked
dependency licenses, a production-safe embedded runtime and keyring validation.
The bundled NativePHP PHP runtime must support required extensions; Telegram
MTProto performs best with GMP.

## Activities and approval

```sh
php artisan automatic-kimai:sync
php artisan automatic-kimai:sync --status
php artisan automatic-kimai:publish-approved
php artisan automatic-kimai:publish-approved --reconcile-unknown
php scripts/check-publication.php
```

Without an authorized supervised cutover, commands do not POST work entries.
Local ActivityWatch evidence and optional Nextcloud, GitHub, Telegram or Ollama
context do not independently prove the duration of human work or its billing
category. The review engine compares proposals with authenticated Kimai
timesheets, avoids duplication and preserves uncertain observations for
rechecking without inventing approvals.

Telegram only scans groups explicitly selected by the current user. The
application **does not recommend a fixed organization's groups**, and it
does not classify chat messages according to hard-coded project/customer brand
names. Desktop work-context classification uses the user's separately
confirmed profile preferences. Any group may be removed without losing control
of the remaining selected sources.

## Privacy and testing

- Runtime state is stored in the user's private application directory, not Git.
- Encrypted credentials and the associated encryption key must **never** be
  included in any installer, build archive, test fixture or CI artifact.
- App output excludes raw browsing URLs, Telegram message bodies and tokens.
  Do not copy crash reports, logs, credentials or real project names to issues.
- Test fixtures must be synthetic. Run the tracked-source and Git history
  security scans before releasing code to a public audience.
- API credentials, mobile identities and real user metadata remain local to
  the person's deployment; GitHub Actions uses dummy keys and fake endpoints.

Additional requirements: `../PHP_MIGRATION.md`, `../PRIVACY.md`,
`../SECURITY.md`, and `../RELEASE_CHECKLIST.md`.

## Development checks

```sh
php vendor/bin/phpunit --colors=never
php scripts/check-publication.php
node --test desktop-preview/*.test.cjs
cd nativephp/electron
npm ci --ignore-scripts --no-audit --no-fund
npm run plugin:build
npm run build
```

Do not test against a real worker's timesheets unless explicitly performing
a supervised operator exercise. The default test environment uses disposable
SQLite databases and local fake HTTP transports.
