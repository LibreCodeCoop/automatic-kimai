import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { buildUpdateManifests, INSTALLERS } from './create-update-manifests.mjs';

function fixture(fn) {
    const folder = mkdtempSync(join(tmpdir(), 'kimai-update-manifests-'));
    try {
        const installers = [
            ...Object.values(INSTALLERS), 'Automatic.Kimai-0.5.0-arm64.zip',
            'Automatic.Kimai-0.5.0-x64.zip',
        ];
        installers.forEach((name, i) => {
            const buffer = Buffer.alloc(20_000_020, i + 1);
            writeFileSync(join(folder, name), buffer);
        });
        fn(folder, installers);
    } finally {
        rmSync(folder, { force: true, recursive: true });
    }
}

test('release files use canonical names and exact base64 SHA-512', () => {
    fixture(folder => {
        assert.deepEqual(buildUpdateManifests(folder, 'v0.5.0'), ['latest-linux.yml', 'latest.yml']);
        for (const [manifest, file] of [
            ['latest-linux.yml', INSTALLERS.linux],
            ['latest.yml', INSTALLERS.windows],
        ]) {
            const text = readFileSync(join(folder, manifest), 'utf8');
            const expected = createHash('sha512').update(readFileSync(join(folder, file))).digest('base64');
            assert.match(text, /^version: 0\.5\.0\n/);
            assert.ok(text.includes(`url: ${file}`));
            assert.ok(text.includes(`sha512: ${expected}`));
        }
    });
});

test('macOS update feed is emitted only for proven signed builds', () => {
    fixture(folder => {
        buildUpdateManifests(folder, '0.5.0', true);
        const yaml = readFileSync(join(folder, 'latest-mac.yml'), 'utf8');
        assert.ok(yaml.includes('Automatic.Kimai-0.5.0-arm64.zip'));
        assert.ok(yaml.includes('Automatic.Kimai-0.5.0-x64.zip'));
        assert.equal((yaml.match(/\n  - url:/g) || []).length, 2);
    });
});

test('rejects prerelease tags, missing archives, and duplicate publication', () => {
    fixture(folder => {
        assert.throws(() => buildUpdateManifests(folder, 'v0.5.0-alpha.1'), /stable/);
        buildUpdateManifests(folder, '0.5.0');
        assert.throws(() => buildUpdateManifests(folder, '0.5.0'), /already exists/);
    });
    const empty = mkdtempSync(join(tmpdir(), 'kimai-empty-updates-'));
    try { assert.throws(() => buildUpdateManifests(empty, '0.5.0'), /Missing/); }
    finally { rmSync(empty, { force: true, recursive: true }); }
});
