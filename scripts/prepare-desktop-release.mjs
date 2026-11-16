#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
// Validate and stage a complete desktop release without publishing anything.
// Safe to exercise with fixtures; GitHub publishing remains a separate step.
import { createHash } from 'node:crypto';
import { closeSync, openSync, readSync, readdirSync, readFileSync, renameSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildUpdateManifests, INSTALLERS } from './create-update-manifests.mjs';
import { assertReleaseTag, readDesktopVersion } from './desktop-version.mjs';

const MARKERS = ['verified-mac-arm64.marker', 'verified-mac-x64.marker'];
const MIN_ASSET_SIZE = 20_000_000;

function sha256(filename) {
    const hash = createHash('sha256');
    const buffer = Buffer.alloc(1024 * 1024);
    const fd = openSync(filename, 'r');
    try {
        let count;
        while ((count = readSync(fd, buffer, 0, buffer.length, null)) > 0) {
            hash.update(buffer.subarray(0, count));
        }
    } finally {
        closeSync(fd);
    }
    return hash.digest('hex');
}

export function prepareDesktopRelease(folder, tag, version) {
    assertReleaseTag(version, 'tag', tag);
    const names = readdirSync(folder).sort();
    const inputs = [
        [/\.AppImage$/, INSTALLERS.linux],
        [/-setup\.exe$/, INSTALLERS.windows],
        [/-arm64\.dmg$/, INSTALLERS.macArm64],
        [/-x64\.dmg$/, INSTALLERS.macX64],
        [/-arm64\.zip$/, `Automatic.Kimai-${version}-arm64.zip`],
        [/-x64\.zip$/, `Automatic.Kimai-${version}-x64.zip`],
    ];
    const moves = inputs.map(([pattern, destination]) => {
        const matches = names.filter(name => pattern.test(name));
        if (matches.length !== 1) throw new Error(`Expected exactly one release asset for ${destination}`);
        const source = matches[0];
        if (!/^[A-Za-z0-9._-]+$/.test(source) || !source.includes(`-${version}-`)) {
            throw new Error(`Wrong version or unsafe release asset: ${source}`);
        }
        const file = join(folder, source);
        if (!statSync(file).isFile() || statSync(file).size < MIN_ASSET_SIZE) {
            throw new Error(`Release asset missing or suspiciously small: ${source}`);
        }
        return { source, destination };
    });
    const foundMarkers = MARKERS.filter(name => names.includes(name));
    if (foundMarkers.length === 1) {
        throw new Error('Only one macOS architecture is signed; refusing partial update feed');
    }
    for (const marker of foundMarkers) {
        if (readFileSync(join(folder, marker), 'utf8') !== 'verified\n') {
            throw new Error(`Invalid macOS signature proof: ${marker}`);
        }
    }
    const expected = new Set([...moves.map(move => move.source), ...foundMarkers]);
    const unexpected = names.filter(name => !expected.has(name));
    if (unexpected.length) throw new Error(`Unexpected release artifacts: ${unexpected.join(', ')}`);
    for (const { source, destination } of moves) {
        if (source !== destination && names.includes(destination)) {
            throw new Error(`Release asset name collision: ${destination}`);
        }
    }

    for (const { source, destination } of moves) {
        if (source !== destination) renameSync(join(folder, source), join(folder, destination));
    }
    for (const marker of foundMarkers) {
        // The marker is an internal build attestation, never a public asset.
        unlinkSync(join(folder, marker));
    }
    if (!version.includes('-')) buildUpdateManifests(folder, tag, foundMarkers.length === MARKERS.length);

    const published = readdirSync(folder).sort();
    const checksums = published.map(name => `${sha256(join(folder, name))}  ${name}`).join('\n') + '\n';
    writeFileSync(join(folder, 'SHA256SUMS.txt'), checksums, { flag: 'wx', mode: 0o644 });
    return { files: [...published, 'SHA256SUMS.txt'], macUpdatesEnabled: foundMarkers.length === MARKERS.length };
}


if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        if (process.argv.length !== 4) throw new Error('Usage: prepare-desktop-release.mjs <folder> <tag>');
        const result = prepareDesktopRelease(resolve(process.argv[2]), process.argv[3], readDesktopVersion());
        console.log(`DESKTOP_RELEASE_FILES=${result.files.join(',')}`);
        console.log(`DESKTOP_MAC_UPDATES_ENABLED=${result.macUpdatesEnabled}`);
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
