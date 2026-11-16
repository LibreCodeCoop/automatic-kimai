#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
// Generate reproducible electron-updater v6 metadata after installers receive
// their canonical GitHub names. All hashes are computed from final asset bytes.
import { createHash } from 'node:crypto';
import { closeSync, openSync, readSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const INSTALLERS = Object.freeze({
    linux: 'Automatic-Kimai-linux-x86_64.AppImage',
    windows: 'Automatic-Kimai-windows-x64-setup.exe',
    macArm64: 'Automatic-Kimai-macos-arm64.dmg',
    macX64: 'Automatic-Kimai-macos-x64.dmg',
});

const PATTERN = /^v?(\d+)\.(\d+)\.(\d+)$/;

export function buildUpdateManifests(folder, tag, signedMac = false) {
    const match = PATTERN.exec(tag);
    if (!match) throw new Error('Only tagged stable releases may emit update manifests');
    const version = match.slice(1).join('.');
    const files = new Set(readdirSync(folder));
    for (const name of Object.values(INSTALLERS)) {
        if (!files.has(name)) throw new Error('Missing canonical installer: ' + name);
    }
    const macArm = `Automatic.Kimai-${version}-arm64.zip`;
    const macIntel = `Automatic.Kimai-${version}-x64.zip`;
    if (!files.has(macArm) || !files.has(macIntel)) {
        throw new Error('Missing macOS zip needed by future signed updates');
    }

    const info = name => {
        if (!/^[A-Za-z0-9._-]+$/.test(name) || name.startsWith('.')) {
            throw new Error('Unsafe update asset name');
        }
        const filename = join(folder, name);
        const stats = statSync(filename);
        if (!stats.isFile() || stats.size < 20_000_000) {
            throw new Error('Update asset is missing or suspiciously small: ' + name);
        }
        const hash = createHash('sha512');
        const buffer = Buffer.alloc(1024 * 1024);
        const fd = openSync(filename, 'r');
        try {
            let size;
            while ((size = readSync(fd, buffer, 0, buffer.length, null)) > 0) {
                hash.update(buffer.subarray(0, size));
            }
        } finally {
            closeSync(fd);
        }
        return { url: name, sha512: hash.digest('base64'), size: stats.size };
    };
    const makeYaml = names => {
        const list = names.map(info);
        const first = list[0];
        return [
            `version: ${version}`,
            'files:',
            ...list.flatMap(file => [
                `  - url: ${file.url}`,
                `    sha512: ${file.sha512}`,
                `    size: ${file.size}`,
            ]),
            `path: ${first.url}`,
            `sha512: ${first.sha512}`,
            '',
        ].join('\n');
    };
    const results = {
        'latest-linux.yml': makeYaml([INSTALLERS.linux]),
        'latest.yml': makeYaml([INSTALLERS.windows]),
    };
    // A macOS update can only be applied to a signed & notarized .app.
    // Do not advertise unsigned ZIP archives as installable updates.
    if (signedMac) results['latest-mac.yml'] = makeYaml([macArm, macIntel]);
    for (const [filename, content] of Object.entries(results)) {
        if (files.has(filename)) throw new Error('Update manifest already exists: ' + filename);
        writeFileSync(join(folder, filename), content, { flag: 'wx', mode: 0o644 });
    }
    return Object.keys(results);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const names = buildUpdateManifests(resolve(process.argv[2] || '.'), process.argv[3], process.argv[4] === 'signed-mac');
    console.log('UPDATE_MANIFESTS=' + names.join(','));
}
