// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { assertReleaseTag, readDesktopVersion } from './desktop-version.mjs';

function fixture(pkgVersion, lockVersion, fn) {
    const root = mkdtempSync(join(tmpdir(), 'automatic-kimai-version-'));
    const electron = join(root, 'nativephp', 'electron');
    try {
        mkdirSync(electron, { recursive: true });
        writeFileSync(join(electron, 'package.json'), JSON.stringify({ name: 'automatic-kimai', version: pkgVersion }));
        writeFileSync(join(electron, 'package-lock.json'), JSON.stringify({
            name: 'automatic-kimai', version: lockVersion,
            packages: { '': { name: 'automatic-kimai', version: lockVersion } },
        }));
        fn(root);
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
}

test('desktop version comes from the package, not the workflow or PHP', () => {
    fixture('2.8.13', '2.8.13', root => assert.equal(readDesktopVersion(root), '2.8.13'));
    fixture('3.0.0-rc.1', '3.0.0-rc.1', root => assert.equal(readDesktopVersion(root), '3.0.0-rc.1'));
});

test('mismatched lockfile and invalid versions fail the build', () => {
    fixture('2.8.14', '2.8.13', root => assert.throws(() => readDesktopVersion(root), /lockfile/));
    fixture('next', 'next', root => assert.throws(() => readDesktopVersion(root), /Invalid/));
});

test('tagged builds must match exactly, while PR builds are version-independent', () => {
    assert.doesNotThrow(() => assertReleaseTag('2.8.13', 'tag', 'v2.8.13'));
    assert.throws(() => assertReleaseTag('2.8.13', 'tag', 'v2.8.12'), /does not match/);
    assert.doesNotThrow(() => assertReleaseTag('2.8.13', 'branch', 'feat/test'));
});
