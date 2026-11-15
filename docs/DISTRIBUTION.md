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
matching the version in `nativephp/electron/package.json`. A tag triggers
GitHub release upload only after all four package jobs succeed and their
installer checks verify the files and embedded PHP runtime.

PR builds produce temporary workflow artifacts only. Tag builds are published
as an explicitly marked **pre-release** while code-signing and on-device
verification remain unproven. The `GITHUB_TOKEN` for publishing exists
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

The application keeps automatic updates disabled until the full signed update
path is verified. Users can update manually from GitHub Releases, preserving
their application-data directory.

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

Promote a pre-release to stable only after signed binaries, actual
Windows/macOS/Linux launch tests, preserved encrypted settings across
updates, permission tests and a review of remaining dependency advisories.
Never describe a successful cross-platform *build* as a verified user
installation.

## Releasing

1. Merge the tested PR, updating package and NativePHP version consistently.
2. Create and push a version tag on the merged commit (for example
   `v0.4.0-alpha.2`). The workflow builds all four targets.
3. Check the workflow result, the four assets, `SHA256SUMS.txt`, and
   the release notes. Never upload unsigned installers to a stable release.
4. For a future stable version, configure signing and notarization credentials,
   validate installation on each real OS, and separately enable and test
   the NativePHP updater only after it can verify trusted update payloads.
