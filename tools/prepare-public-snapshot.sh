#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Create an isolated, history-free, *uncommitted* public source snapshot.
# This script never touches remote refs or pushes any branch.
set -euo pipefail

if [[ "$#" -ne 1 || "$1" != /* || "$1" == "/" ]]; then
  echo 'Usage: prepare-public-snapshot.sh ABSOLUTE_NEW_DIRECTORY' >&2
  exit 2
fi
dest="$1"
if [[ -e "$dest" || -L "$dest" ]]; then
  echo 'Destination must not exist' >&2
  exit 2
fi
root="$(cd "$(dirname "$0")/.." && pwd -P)"
if ! git -C "$root" diff --quiet HEAD ||
   ! git -C "$root" diff --cached --quiet ||
   [[ -n "$(git -C "$root" ls-files --others --exclude-standard)" ]]; then
  echo 'Source worktree must have no changes or untracked content' >&2
  exit 2
fi

php_bin="${AUTOMATIC_KIMAI_PHP_BINARY:-$(command -v php || true)}"
if [[ ! -x "$php_bin" ]]; then
  echo 'PHP executable is required for the source check' >&2
  exit 2
fi
(cd "$root" && "$php_bin" php-app/scripts/check-publication.php)
gitleaks_bin="${GITLEAKS_BIN:-$(command -v gitleaks || true)}"
if [[ ! -x "$gitleaks_bin" ]]; then
  echo 'Gitleaks is required for a history-free public snapshot' >&2
  exit 2
fi

umask 0077
install -d -m 700 "$dest"
git -C "$root" archive --format=tar HEAD | tar -xf - -C "$dest"
git -C "$dest" init -q -b main
git -C "$dest" add -f -A
expected="$(git -C "$root" rev-parse 'HEAD^{tree}')"
actual="$(git -C "$dest" write-tree)"
if [[ "$actual" != "$expected" ]]; then
  echo 'Source tree mismatch: snapshot is NOT verified' >&2
  exit 1
fi
report="$dest/.git/gitleaks-public-snapshot.json"
if ! "$gitleaks_bin" dir "$dest" --redact --report-format json \
  --report-path "$report" >/dev/null 2>&1; then
  echo 'Snapshot contains findings; inspect privately before any publication' >&2
  exit 1
fi
(cd "$dest" && "$php_bin" php-app/scripts/check-publication.php)
echo 'PUBLIC_SNAPSHOT_VERIFIED=true'
echo 'Public root history contains NO commits yet; review the staged source.'
echo 'Create a signed first commit with a reviewed, public author identity.'
echo 'Do not push this snapshot into a repository that still contains old PR refs.'
