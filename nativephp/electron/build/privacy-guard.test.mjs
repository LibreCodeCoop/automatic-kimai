import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { assertNoBundledCredentials } from './privacy-guard.mjs';

function withFixture(run) {
    const root = mkdtempSync(join(tmpdir(), 'automatic-kimai-build-privacy-'));
    mkdirSync(join(root, 'app'), { recursive: true, mode: 0o700 });
    try { run(root, join(root, 'app')); }
    finally { rmSync(root, { recursive: true, force: true }); }
}

test('sanitized app and native empty session placeholders are safe', () => {
    withFixture((root, app) => {
        mkdirSync(join(app, 'storage/framework/sessions'), { recursive: true });
        mkdirSync(join(app, 'storage/logs'), { recursive: true });
        writeFileSync(join(app, 'storage/framework/sessions/_native.json'), '{}');
        writeFileSync(join(app, 'storage/logs/_native.json'), '{}');
        writeFileSync(join(app, '.env'), 'APP_ENV=production\nLOG_LEVEL=warning\nAPP_KEY=\n');
        assert.doesNotThrow(() => assertNoBundledCredentials(root));
    });
});

test('private session folder or encrypted credential file blocks installer', () => {
    for (const path of ['storage/app/private', 'storage/app/private/automatic-kimai']) {
        withFixture((root, app) => {
            mkdirSync(join(app, path), { recursive: true });
            assert.throws(() => assertNoBundledCredentials(root), /forbidden entry/);
        });
    }
});

test('cached Laravel config and external package auth cannot ship', () => {
    for (const file of ['bootstrap/cache/config.php', 'auth.json', '.npmrc']) {
        withFixture((root, app) => {
            const parts = file.split('/');
            if (parts.length > 1) {
                mkdirSync(join(app, ...parts.slice(0, -1)), { recursive: true });
            }
            writeFileSync(join(app, file), 'fake sensitive test only');
            assert.throws(() => assertNoBundledCredentials(root), /forbidden entry/);
        });
    }
});

test('application key or API credentials cannot enter bundle env', () => {
    for (const key of ['APP_KEY', 'TELEGRAM_API_ID', 'TELEGRAM_API_HASH',
        'KIMAI_API_TOKEN', 'NEXTCLOUD_APP_PASSWORD', 'GITHUB_TOKEN',
        'AWS_SECRET_ACCESS_KEY', 'MAIL_PASSWORD']) {
        withFixture((root, app) => {
            writeFileSync(join(app, '.env'), key + '=fixture-credential-not-real\n');
            assert.throws(() => assertNoBundledCredentials(root), new RegExp(key));
        });
    }
});

test('saved browser sessions and application logs cannot ship in installer', () => {
    for (const location of ['storage/framework/sessions', 'storage/logs']) {
        withFixture((root, app) => {
            mkdirSync(join(app, location), { recursive: true });
            writeFileSync(join(app, location, 'sensitive.test'), 'dummy');
            assert.throws(() => assertNoBundledCredentials(root), /runtime files/);
        });
    }
});

test('no prepared app means no installer', () => {
    withFixture(root => {
        rmSync(join(root, 'app'), { recursive: true });
        assert.throws(() => assertNoBundledCredentials(root), /app directory missing/);
    });
});
