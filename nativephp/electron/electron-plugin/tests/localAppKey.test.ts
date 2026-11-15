import { afterEach, describe, expect, test } from 'vitest';
import { chmodSync, existsSync, mkdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'fs';
import { mkdtempSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { loadOrCreateLocalAppKey } from '../src/server/localAppKey.js';

const temporary: string[] = [];
function fresh(): string {
    const dir = mkdtempSync(join(tmpdir(), 'kimai-install-key-'));
    temporary.push(dir);
    return dir;
}
afterEach(() => {
    for (const dir of temporary.splice(0)) rmSync(dir, { recursive: true, force: true });
});

describe('per-user installation key', () => {
    test('is distinct per installation and persisted across restarts', () => {
        const one = fresh(), two = fresh();
        const first = loadOrCreateLocalAppKey(one);
        expect(first).toMatch(/^base64:[a-zA-Z0-9+/]{43}=$/);
        expect(loadOrCreateLocalAppKey(one)).toBe(first);
        expect(loadOrCreateLocalAppKey(two)).not.toBe(first);
        expect(readFileSync(join(one, 'automatic-kimai-app-key'), 'utf8').trim()).toBe(first);
    });
    test('cannot silently regenerate over existing encrypted data', () => {
        const dir = fresh();
        const privateDir = join(dir, 'storage/app/private/automatic-kimai');
        mkdirSync(privateDir, { recursive: true });
        writeFileSync(join(privateDir, 'secrets.enc'), 'synthetic-only');
        expect(() => loadOrCreateLocalAppKey(dir)).toThrow(/existing encrypted/);
        expect(existsSync(join(dir, 'automatic-kimai-app-key'))).toBe(false);
    });
    test('rejects invalid keys and symlinked key files', () => {
        const dir = fresh();
        writeFileSync(join(dir, 'automatic-kimai-app-key'), 'invalid-value', { mode: 0o600 });
        expect(() => loadOrCreateLocalAppKey(dir)).toThrow(/format/);
        if (process.platform !== 'win32') {
            const other = fresh();
            symlinkSync(join(dir, 'automatic-kimai-app-key'), join(other, 'automatic-kimai-app-key'));
            expect(() => loadOrCreateLocalAppKey(other)).toThrow(/file type/);
        }
    });
    test.skipIf(process.platform === 'win32')('refuses broad key permissions', () => {
        const dir = fresh();
        loadOrCreateLocalAppKey(dir);
        chmodSync(join(dir, 'automatic-kimai-app-key'), 0o644);
        expect(() => loadOrCreateLocalAppKey(dir)).toThrow(/unsafe permissions/);
    });
});
