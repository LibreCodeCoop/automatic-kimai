# Preparing Automatic Kimai source for public access

This is a **source audit checklist**, not an authorization to change the
repository visibility. No personal desktop profiles, runtime credentials, chat
messages, private activity databases, or logs belong in a public Git repository.

## Scan and remediation

The public-source CI scans:
- All fetched branch histories with Gitleaks (reports kept on the ephemeral
  runner and never uploaded).
- The current tracked source tree with `php-app/scripts/check-publication.php`.
- Safe example configuration: no active debug output, session payload encryption
  enabled, and no default mail logging.

Before initial publication, also inspect the live repository's releases, tags,
PR attachments, Actions artifacts and existing forks. Source scanners cannot
prove the absence of personal data, undocumented tokens or unrecognized PII.

Maintain a private, **untracked** `.publication-denylist` (permissions 0600)
with any actual customer names, tenant hostnames, directory paths and other
nonpublic markers relevant to the release. Review matches individually:
legitimate copyright/license attribution is not the same as a private tenant
credential or a hard-coded application default. Do not upload the denylist
to PRs, Actions or issues.

## Git history requires a separate coordinated operation

A pull request changes the current tree but **does not remove old commits**.
If historical source includes confidential implementation notes, private
names, email metadata or credentials, fixing only the current branch is
insufficient for publication.

1. Keep the repo private and merge the source-hardening PR. Merge/close any
   other PRs first and coordinate a short write freeze with contributors.
2. **Revoke or rotate any actual credential** before attempting to purge it.
   Rewriting commits never makes a leaked credential safe again.
3. Store a restrictive, access-controlled backup of the original repository
   outside public hosting. Enumerate branches, tags, forks and PR references.
4. In a disposable clone, create a new clean root history from the reviewed
   final source tree or use a recent `git-filter-repo` with
   `--sensitive-data-removal` to remove exact exposed files/strings.
   Verify the new tree and **all new refs** with Gitleaks and the publication
   check; compare the tree against the approved release.
5. Only after independent review, update the canonical GitHub branch/refs
   during the maintenance window. A destructive history rewrite invalidates
   commit signatures, prior SHAs, open PR comparisons and some reviews.
   Protect against stale developer clones pushing old commits back again.
6. GitHub retains internal PR references, caches and potentially unreachable
   objects after a force push. For genuinely sensitive data, coordinate
   cleanup with GitHub Support. GitHub Support may decline requests that
   can be addressed by credential rotation, or those concerning nonsensitive
   history. Existing external clones/forks cannot be purged remotely.
7. After verification of the new public-visible refs, enable GitHub secret
   scanning and push protection where available, private vulnerability
   reporting and Dependabot security alerts. Confirm the public clone has
   no access to private state.

**Never run `git push --force --mirror` on the production repository from
an unreviewed PR or a working clone that contains unexpected refs.** History
destruction is a separate operator action, not a CI task.

## Local development data

Use `php-app/.env.example` to create a unique local `.env` and generate a
new Laravel encryption key. Keep local session stores, Telegram credentials,
ActivityWatch data and SQLite databases under owner-only permissions. Do not
copy them to development fixtures, logs, screenshots, PR attachments or
releases.

Public source availability is not proof that the desktop installer is
safe to distribute or that PHP can replace an existing Python writer.
Deployment, secret isolation in packaged binaries, dependency licenses and
runtime cutover have their own verification gates.

GitHub's documented removal process:
https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/removing-sensitive-data-from-a-repository

### Optional offline clean-history snapshot

After merging the hardening PR and reviewing the final `main` tree, an
operator can prepare an **uncommitted** history-free snapshot in a brand-new
private directory:

```sh
GITLEAKS_BIN=/absolute/path/to/gitleaks \
AUTOMATIC_KIMAI_PHP_BINARY=/absolute/path/to/php \
bash tools/prepare-public-snapshot.sh /absolute/new/private/source-directory
```

The script requires a clean worktree, verifies the exact Git tree hash, runs
both source and Gitleaks scans, and initializes **zero previous commits**.
It does **not** create a commit, change any remote, bypass branch protections,
or delete the original repository. An operator must review the snapshot and
configure an appropriate public signing identity before its first commit.
Only a separately reviewed repository/visibility transition may publish it.

If the original GitHub repository is made public directly, old commits and PR
references are still potentially retrievable. A new public repository based on
the clean snapshot avoids exposing the original repository's private history.

## Dependency review of the current alpha (15 November 2026)

The original GitHub default branch reported seven Dependabot advisories:
two **high** `extract-zip` alerts and five **moderate** alerts.
The application did not directly use `electron-chromedriver`, which was
the sole dependency pulling the unpatched `extract-zip` package. The
hardening branch removes it entirely, along with unused `nodemon`.
Vitest/`@vitest/mocker`, `colord`, and `rimraf`/`glob` have been updated
to versions outside the reported vulnerable ranges. Both a clean npm
install and Electron's local bundle/tests were verified after updating
the lockfile; npm audit and Composer audit reported zero advisories in
their own advisory databases.

**Residual upstream risk:** the Electron packaging/development dependency
tree still includes `minimatch` 9.x and 10.x through packages such as
`@electron/universal`, `app-builder-lib`, and build tooling. GitHub
advisory `GHSA-g3hv-75wg-m249` recommends minimatch 11.0.1+, but those
upstreams currently declare incompatible older major ranges. Do **not**
force a major override merely to suppress Dependabot without testing
the actual installer and all affected dependency consumers. Until a safe
upstream release or verified compatible upgrade is available, do not
process third-party/untrusted glob patterns in packaging workflows.
An independent review of this remaining advisory is required before
shipping signed desktop installers.

Dependabot counts are calculated from GitHub's **default branch**. The
alerts cannot be presumed closed until the hardening PR is merged and
the dependency graph is reprocessed. The npm advisory feed and the
GitHub advisory feed need not report identical results at the same time.
