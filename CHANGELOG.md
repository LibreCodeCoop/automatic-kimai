# Changelog

## 0.4.0 — Public desktop release (November 2026)

- Public release with AppImage, NSIS .exe and macOS Intel/Apple Silicon DMG.
- Fixed the minimatch dependency advisory in the build toolchain while
  retaining the compatibility required by legacy glob consumers.
- Distribution notes explicitly disclose unsigned installer status, disabled
  automatic updates and the need for physical-device acceptance testing.


## 0.4.0-alpha.2 — Release signing configuration (November 2026)

- Fixed an empty-certificate release-only failure in the macOS builder:
  unsigned previews no longer interpret an empty certificate URL as a file.
- Added CI coverage for missing signing credentials and preserved normal
  certificate-based signing when secrets are configured.


## 0.4.0-alpha.1 — Desktop packaging (November 2026)

- NativePHP installer pipelines for Linux AppImage, Windows NSIS and macOS
  Intel/Apple Silicon DMG, with embedded PHP and release checksums.
- A separate, persistent encryption key per OS user profile; no install-wide
  encryption key or source credentials inside downloadable packages.
- Product-focused download and integration information in the README.
- Prepared public GitHub release distribution. Unsigned packages and update
  behavior require validation before promotion to a stable signed release.


## [0.3.0a1] — source-release candidate (2026-11)

- Separated the portable ActivityWatch implementation from the earlier local pilot importer.
- Removed organization-specific identifiers from the public application and example fixtures.
- Documented first-run configuration, privacy/security, contributing guidelines and release criteria.
- Added regression tests for the portable collector choice and retained previous midnight, gap and reconciliation tests.
- Prepared a GPL-3.0-or-later licensing **proposal** pending confirmation of copyright holders.
- Added source-quality CI and publication checks; desktop installers remain experimental and unsigned.

Earlier internal pilot changes were not public releases and are not described as published versions.
