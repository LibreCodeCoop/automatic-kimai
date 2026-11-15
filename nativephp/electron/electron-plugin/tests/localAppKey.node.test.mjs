import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync, chmodSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadOrCreateLocalAppKey } from '../dist/server/localAppKey.js';

test('separate installations use persistent and unique application keys', () => {
    const a = mkdtempSync(join(tmpdir(), 'kimai-key-a-'));
    const b = mkdtempSync(join(tmpdir(), 'kimai-key-b-'));
    try {
        const key = loadOrCreateLocalAppKey(a);
        assert.match(key, /^base64:[A-Za-z0-9+/]{43}=$/);
        assert.equal(loadOrCreateLocalAppKey(a), key);
        assert.notEqual(loadOrCreateLocalAppKey(b), key);
    } finally {
        rmSync(a, { recursive: true, force: true });
        rmSync(b, { recursive: true, force: true });
    }
});

test('key loss cannot silently overwrite encrypted user state', () => {
    const dir = mkdtempSync(join(tmpdir(), 'kimai-key-lost-'));
    try {
        const p = join(dir, 'storage/app/private/automatic-kimai');
        mkdirSync(p, { recursive: true });
        writeFileSync(join(p, 'secrets.enc'), 'test-only');
        assert.throws(() => loadOrCreateLocalAppKey(dir), /existing encrypted/);
    } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('invalid or insecure key file is refused', () => {
    const dir = mkdtempSync(join(tmpdir(), 'kimai-key-bad-'));
    try {
        const file = join(dir, 'automatic-kimai-app-key');
        writeFileSync(file, 'invalid', { mode: 0o600 });
        assert.throws(() => loadOrCreateLocalAppKey(dir), /format/);
        if (process.platform !== 'win32') {
            chmodSync(file, 0o644);
            assert.throws(() => loadOrCreateLocalAppKey(dir), /permissions/);
            const linked = mkdtempSync(join(tmpdir(), 'kimai-key-link-'));
            try {
                symlinkSync(file, join(linked, 'automatic-kimai-app-key'));
                assert.throws(() => loadOrCreateLocalAppKey(linked), /file type/);
            } finally { rmSync(linked, { recursive: true, force: true }); }
        }
    } finally { rmSync(dir, { recursive: true, force: true }); }
});
