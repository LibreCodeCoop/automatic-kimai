import test from 'node:test';
import assert from 'node:assert/strict';
import { canonicalReleaseAssetName } from './check-desktop-package.mjs';

test('published asset names are identical to checksum filenames', () => {
    const names = [
        'Automatic Kimai-0.4.0-alpha.2-x86_64.AppImage',
        'Automatic Kimai-0.4.0-alpha.2-setup.exe',
        'Automatic Kimai-0.4.0-alpha.2-x64.dmg',
        'Automatic Kimai-0.4.0-alpha.2-arm64.dmg',
    ];
    for (const file of names) {
        assert.equal(canonicalReleaseAssetName(file), file.replace(' ', '.'));
        assert.equal(canonicalReleaseAssetName(canonicalReleaseAssetName(file)),
                     canonicalReleaseAssetName(file));
    }
});

test('filename normalization refuses unusual or dangerous paths', () => {
    for (const value of ['', '../file', '/tmp/file', '..\\file', '.env', 'secret<>key']) {
        assert.throws(() => canonicalReleaseAssetName(value));
    }
});
