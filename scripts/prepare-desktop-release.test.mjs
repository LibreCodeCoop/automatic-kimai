// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readdirSync, readFileSync, renameSync, rmSync, truncateSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { prepareDesktopRelease } from './prepare-desktop-release.mjs';

const VERSION = '2.8.13';
const installerNames = [
    `Automatic.Kimai-${VERSION}-x86_64.AppImage`,
    `Automatic.Kimai-${VERSION}-setup.exe`,
    `Automatic.Kimai-${VERSION}-arm64.dmg`,
    `Automatic.Kimai-${VERSION}-x64.dmg`,
    `Automatic.Kimai-${VERSION}-arm64.zip`,
    `Automatic.Kimai-${VERSION}-x64.zip`,
];

function fixture(fn) {
    const folder = mkdtempSync(join(tmpdir(), 'automatic-kimai-release-'));
    try {
        for (const name of installerNames) {
            const path = join(folder, name);
            writeFileSync(path, 'test-artifact');
            truncateSync(path, 20_000_001);
        }
        fn(folder);
    } finally {
        rmSync(folder, { force: true, recursive: true });
    }
}

test('stages canonical filenames, update manifests and checked SHA-256 with no release publish', () => {
    fixture(folder => {
        const result = prepareDesktopRelease(folder, `v${VERSION}`, VERSION);
        assert.equal(result.macUpdatesEnabled, false);
        assert.equal(result.files.length, 9); // Six installers, two update manifests, checksum file.
        assert.equal(readdirSync(folder).length, 9);
        assert.equal(readdirSync(folder).some(name => name.startsWith('verified-mac')), false);
        assert.equal(readdirSync(folder).includes('latest-mac.yml'), false);
        const checksumLines = readFileSync(join(folder, 'SHA256SUMS.txt'), 'utf8').trim().split('\n');
        assert.equal(checksumLines.length, 8);
        const linux = 'Automatic-Kimai-linux-x86_64.AppImage';
        const expected = createHash('sha256').update(readFileSync(join(folder, linux))).digest('hex');
        assert.ok(checksumLines.includes(`${expected}  ${linux}`));
    });
});

test('both macOS proofs enable manifest, but single or forged proof is rejected', () => {
    fixture(folder => {
        writeFileSync(join(folder, 'verified-mac-arm64.marker'), 'verified\n');
        assert.throws(() => prepareDesktopRelease(folder, `v${VERSION}`, VERSION), /Only one/);
        assert.equal(readdirSync(folder).includes(installerNames[0]), true); // No rename on failed validation.
        writeFileSync(join(folder, 'verified-mac-x64.marker'), 'forged\n');
        assert.throws(() => prepareDesktopRelease(folder, `v${VERSION}`, VERSION), /Invalid macOS signature proof/);
        writeFileSync(join(folder, 'verified-mac-x64.marker'), 'verified\n');
        const result = prepareDesktopRelease(folder, `v${VERSION}`, VERSION);
        assert.equal(result.macUpdatesEnabled, true);
        assert.match(readFileSync(join(folder, 'latest-mac.yml'), 'utf8'), /arm64\.zip/);
        assert.equal(readdirSync(folder).length, 10);
    });
});

test('rejects foreign version, extra files and missing packages before modifying artifacts', () => {
    fixture(folder => {
        assert.throws(() => prepareDesktopRelease(folder, 'v2.8.12', VERSION), /does not match/);
        writeFileSync(join(folder, 'extra.txt'), 'surprise');
        assert.throws(() => prepareDesktopRelease(folder, `v${VERSION}`, VERSION), /Unexpected/);
        rmSync(join(folder, 'extra.txt'));
        rmSync(join(folder, installerNames[1]));
        assert.throws(() => prepareDesktopRelease(folder, `v${VERSION}`, VERSION), /exactly one/);
    });
});

test('pre-release packages get checksum files but never a stable update feed', () => {
    fixture(folder => {
        const pre = `${VERSION}-rc.1`;
        for (const name of installerNames) {
            const renamed = name.replace(`-${VERSION}-`, `-${pre}-`);
            const src = join(folder, name);
            const dest = join(folder, renamed);
            renameSync(src, dest);
        }
        const result = prepareDesktopRelease(folder, `v${pre}`, pre);
        assert.equal(result.files.length, 7);
        assert.equal(readdirSync(folder).includes('latest.yml'), false);
    });
});
