# Desktop distribution — maintainers

Release packages are built from a fresh Git checkout using
`.github/workflows/desktop-release.yml`. The application is a Laravel
project at the repository root; NativePHP/Electron lives under
`nativephp/electron/`.

## Package matrix

- Ubuntu x64 → AppImage
- Windows x64 → NSIS setup `.exe`
- macOS Intel x64 → `.dmg`
- macOS Apple Silicon arm64 → `.dmg`

The workflow runs on PRs affecting packaging and on signed release tags
matching the version in `nativephp/electron/package.json` (the single canonical
application version). npm maintains `package-lock.json`, which is checked for
consistency. CI resolves `NATIVEPHP_APP_VERSION` from this package; do not set
release versions in the workflow or PHP configuration. A tag triggers
GitHub release upload only after all four package jobs succeed and their
installer checks verify the files and embedded PHP runtime.

PR builds produce temporary workflow artifacts only. A tag containing
a prerelease suffix is labeled **pre-release**; normal version tags are
published as regular GitHub releases. Release notes disclose any missing
code signatures, notarization and on-device verification. The `GITHUB_TOKEN` for publishing exists
only in the final release job.

## Security and installation lifecycle

Every build must use a disposable checkout, an unpopulated `APP_KEY`,
synthetic data and no private runtime files. Source preflight is enforced by
`NativeBuildGuard` and the CI step; `beforePack` independently checks the
prepared app's environment and storage. NativePHP does not honor Git's
`.gitignore` when copying application resources.

The installed Electron process generates a unique 32-byte Laravel APP_KEY on
first launch and stores it in the OS user's application-data directory as
`automatic-kimai-app-key`, with owner-only permissions on Unix. The same
key is reused after restart and updates; if an encrypted profile exists
without its original key, startup fails instead of silently replacing it.
The installer itself never contains a shared decryption key.

Automatic update checking is now handled in Electron's main process, not
the Laravel HTTP controller. Requests are restricted to the main local frame.
Every installed app checks for official stable GitHub releases at startup and
every six hours. A newer release is offered in the UI but not downloaded
without consent. Once the electron-updater verifies it, the user can choose
“Reiniciar e atualizar”. The updater keeps credentials and local history
outside the versioned application payload.

The publishing workflow builds release metadata for Linux AppImage
(`latest-linux.yml`) and Windows NSIS (`latest.yml`) from the exact final
installer bytes; the files contain SHA-512 digests. macOS builds include ZIP
archives, but `latest-mac.yml` is published only after both architecture
builds pass Developer ID and Gatekeeper verification. Unsigned macOS users
see a link to the official release rather than a misleading restart button.

These safeguards do not replace on-device testing and certificate management.
The v0.4.0 generation cannot self-update because it shipped without an updater;
one initial manual installation is needed. Never force auto-download or
install-on-quit in the background.

## Platform signatures

No Apple/Windows signing certificates were present in this repository when
the workflow was introduced. **Unsigned preview installers are not
homologated production installers.**

For a stable macOS distribution, register repository secrets for
`MAC_CSC_LINK`, `MAC_CSC_KEY_PASSWORD`, `MAC_APPLE_ID`,
`MAC_APPLE_ID_PASS` and `MAC_APPLE_TEAM_ID`. The Apple
Developer ID certificate must be valid, and notarization must succeed.
The notarization script fails the build when an attempted notarization fails.

For Windows Authenticode signing, provide `WIN_CSC_LINK` and
`WIN_CSC_KEY_PASSWORD`. No signing credentials are exposed to PR builds.
Linux packages are unsigned by the OS; release checksums provide integrity
evidence but **not** publisher authentication.

GitHub's regular-release flag means a publicly listed download, **not**
that the installer has independent code-signing attestation or completed
cross-platform operational certification. Stable production suitability
requires signed binaries, real Windows/macOS/Linux launch tests, preserved
encrypted settings across updates, permission tests and vulnerability review.
Never describe a successful cross-platform *build* as a verified user installation.

## Releasing

1. Set the intended version with `npm version <version> --no-git-tag-version`
   inside `nativephp/electron/`; commit both npm metadata files together.
   `node scripts/desktop-version.mjs` validates their consistency.
2. Run the release preparation tests without a release:
   `node --test scripts/desktop-version.test.mjs scripts/create-update-manifests.test.mjs scripts/prepare-desktop-release.test.mjs`.
   The preparation function can be exercised using fixture artifacts and
   does not call GitHub or publish anything.
3. Merge the tested PR; create a tag corresponding to the package version.
   Four package jobs produce six archives. A dedicated script
   (`scripts/prepare-desktop-release.mjs`) checks every artifact, renames
   installers, generates update manifests and SHA256 checksums. The short
   workflow publishes these assets with `.github/RELEASE_NOTES.md`.
4. Verify the published installers, macOS archives, update manifests,
   checksums and release notes. Configure signing/notarization and
   homologate upgrade, rollback and encrypted settings on real devices.
   Unsigned macOS releases must not advertise automatic installation.
