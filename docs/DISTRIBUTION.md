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

The workflow builds pull-request previews and evaluates each push to `main`
for a stable release based on Conventional Commits since the previous stable
tag. `fix:` and `perf:` mean PATCH, `feat:` means MINOR; the `!` marker
or a `BREAKING CHANGE:` footer means MAJOR. The highest increment wins.
`docs:`, `test:`, `chore:` and non-conventional commits alone do not
cause a release. A documentation-only merge skips installers entirely.

The version is calculated from Git history, not hardcoded in the workflow.
The release build updates `nativephp/electron/package.json` and its
npm-generated lockfile in the packaging workspace. After all four OS jobs
and package validation pass, it tags the exact sources with updated version
metadata. This version-only release commit is attached to the tag, **not**
merged into `main`; the application code remains identical to the merged
commit. Future release planning compares `main` against the previous
release tag or its main parent, preventing duplicate releases. The package
version is canonical for a checkout or installed release; no independent
manually managed version belongs in PHP or GitHub Actions.

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

1. Merge PRs with Conventional Commits into `main`. The desktop workflow
   calculates a version from the last stable `vX.Y.Z` tag. Documentation-only
   and test-only changes do not cause a release.
2. For releasable commits, the same run stamps the calculated version into
   npm metadata and compiles Linux, Windows and macOS packages. All four
   builds and tests must pass before assets can be published.
3. The publish job validates canonical assets, update manifests and checksums,
   generates notes from relevant commits, pushes the release tag and uploads
   all packages to a **draft** GitHub release. It publishes the draft only
   once the uploads have completed.
4. Do not manually bump the package version or create stable tags during
   ordinary merges. The publishing job needs repository `contents: write`.
   Tags pushed by the default `GITHUB_TOKEN` will not trigger another run;
   build and publication intentionally happen in the same workflow.
5. If a tag was pushed but publication failed, manually run
   `.github/workflows/desktop-release.yml` using its `release_tag` input
   to rebuild that exact version. Never force-update a release tag or delete
   a published release to hide failed publication.
6. Validate installation, automatic upgrades, rollback and preservation
   of encrypted settings on physical Windows, macOS and Linux devices.
   Successful automated builds are not a substitute for this verification.

The tag source records the actual published package version; `main` does
not receive an automated version-only commit. Prerelease tags may still be
pushed manually when preparing a preview.
