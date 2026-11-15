import { existsSync, lstatSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Fail package creation if the prepared NativePHP app could embed any local
 * Telegram/Nextcloud/Kimai credentials or authenticated sessions.
 * NativePHP cleanup remains the primary filter; this is an independent check.
 */
export function assertNoBundledCredentials(buildPath) {
    if (!buildPath || typeof buildPath !== 'string') {
        throw new Error('Private build guard requires NATIVEPHP_BUILD_PATH');
    }

    const appDir = join(buildPath, 'app');
    if (!existsSync(appDir)) {
        throw new Error('Private build guard: prepared app directory missing');
    }

    const forbiddenEntries = [
        'storage/app/private',
        'storage/app/private/automatic-kimai',
        '.env.production',
        '.env.local',
        'bootstrap/cache/config.php',
        'auth.json',
        '.npmrc',
    ];

    for (const relative of forbiddenEntries) {
        if (existsSync(join(appDir, relative))) {
            // File names can be reported, but never secret contents.
            throw new Error('Private build guard: forbidden entry ' + relative);
        }
    }

    for (const relative of ['storage/framework/sessions', 'storage/logs']) {
        const directory = join(appDir, relative);
        if (!existsSync(directory)) continue;
        if (!lstatSync(directory).isDirectory()) {
            throw new Error('Private build guard: unsafe ' + relative + ' entry');
        }
        const unexpected = readdirSync(directory).filter((entry) => entry !== '_native.json');
        if (unexpected.length) {
            throw new Error('Private build guard: runtime files under ' + relative);
        }
    }

    const envFile = join(appDir, '.env');
    if (!existsSync(envFile)) return;

    if (!lstatSync(envFile).isFile()) {
        throw new Error('Private build guard: unexpected .env type');
    }

    // A sanitized .env with nonsecret defaults is expected in NativePHP.
    // Never log lines or values, even on failure.
    for (const line of readFileSync(envFile, 'utf8').split(/\r?\n/)) {
        const parsed = line.match(/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/);
        if (!parsed) continue;
        const key = parsed[1];
        const value = parsed[2].trim().replace(/^["']|["']$/g, '');
        const confidential =
            key === 'APP_KEY' ||
            key.startsWith('TELEGRAM_') ||
            key.startsWith('KIMAI_') ||
            key.startsWith('NEXTCLOUD_') ||
            key.startsWith('OPENAI_') ||
            key.startsWith('ANTHROPIC_') ||
            key.startsWith('GITHUB_') ||
            key.startsWith('AWS_') ||
            key.startsWith('AZURE_') ||
            /(?:_PASSWORD|_TOKEN|_SECRET|_API_HASH|_PRIVATE_KEY)$/.test(key);
        if (confidential && value !== '') {
            throw new Error('Private build guard: ' + key + ' must not ship in app environment');
        }
    }
}
