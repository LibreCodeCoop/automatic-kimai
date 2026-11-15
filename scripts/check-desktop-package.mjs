#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
// Validate NativePHP packaging outputs before uploading or publishing them.
import { existsSync, readFileSync, readdirSync, statSync, mkdirSync, copyFileSync, openSync, readSync, closeSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

// GitHub release uploads normalize whitespace in asset names. Normalize
// *before* hashing or uploading, so SHA256SUMS.txt references the actual
// public filenames and works with standard `sha256sum -c`.
export function canonicalReleaseAssetName(original) {
    if (typeof original !== 'string' || !original) {
        throw new Error('Invalid desktop release filename');
    }
    const name = original.replace(/\s+/g, '.');
    if (!/^[A-Za-z0-9._-]+$/.test(name) || name.startsWith('.')) {
        throw new Error('Unsafe desktop release filename');
    }
    return name;
}

export function verifyDesktopPackage(root, os, arch) {
    const suffixes = { linux: '.AppImage', win: '-setup.exe', mac: '.dmg' };
    const suffix = suffixes[os];
    if (!suffix || !['x64', 'arm64'].includes(arch)) {
        throw new Error('Unsupported desktop distribution target');
    }
    // NativePHP Desktop stores the prepared bundle under its Composer
    // resources folder, not root/build (which is intentionally excluded).
    const buildPath = join(root, 'vendor', 'nativephp', 'desktop', 'resources', 'build');
    const phpFile = join(buildPath, 'php', os === 'win' ? 'php.exe' : 'php');
    if (!existsSync(phpFile) || !statSync(phpFile).isFile() ||
        statSync(phpFile).size < 5_000_000) {
        throw new Error('NativePHP distribution lacks its standalone PHP runtime');
    }
    const targetHost = { linux: 'linux', win: 'win32', mac: 'darwin' };
    const compatibleArchitecture = (arch === process.arch) ||
        (arch === 'x64' && process.arch === 'x64');
    if (process.platform === targetHost[os] && compatibleArchitecture) {
        try {
            execFileSync(phpFile, ['-v'], { timeout: 15000, stdio: 'ignore' });
        } catch {
            throw new Error('Bundled PHP runtime does not execute on its target OS');
        }
    }
    const appPath = join(buildPath, 'app');
    const envFile = join(appPath, '.env');
    if (!existsSync(join(appPath, 'artisan')) || !existsSync(envFile)) {
        throw new Error('NativePHP distribution lacks its prepared Laravel application');
    }
    const env = readFileSync(envFile, 'utf8');
    for (const line of env.split(/\r?\n/)) {
        if (/^\s*(APP_KEY|KIMAI_[A-Z0-9_]*|TELEGRAM_[A-Z0-9_]*|NEXTCLOUD_[A-Z0-9_]*|GITHUB_TOKEN|AWS_SECRET_ACCESS_KEY)\s*=\s*[^\s#]/.test(line)) {
            throw new Error('Private configuration detected in prepared application');
        }
    }
    if (existsSync(join(appPath, 'storage', 'app', 'private')) ||
        existsSync(join(appPath, 'bootstrap', 'cache', 'config.php'))) {
        throw new Error('Runtime private state leaked into prepared application');
    }
    const folder = join(root, 'nativephp', 'electron', 'dist');
    if (!existsSync(folder)) throw new Error('Electron distribution folder missing');
    const files = readdirSync(folder).filter(name =>
        name.endsWith(suffix) &&
        (os !== 'mac' || name.includes('-' + arch + '.')) &&
        (os !== 'linux' || name.endsWith('.AppImage'))
    );
    if (files.length !== 1) throw new Error('Expected exactly one ' + os + '/' + arch + ' installer');
    const artifact = join(folder, files[0]);
    const stat = statSync(artifact);
    if (!stat.isFile() || stat.size < 20_000_000) throw new Error('Installer missing or suspiciously small');
    const fd = openSync(artifact, 'r');
    let first;
    let trailer;
    try {
        first = Buffer.alloc(16);
        trailer = Buffer.alloc(512);
        readSync(fd, first, 0, first.length, 0);
        readSync(fd, trailer, 0, trailer.length, stat.size - 512);
    } finally {
        closeSync(fd);
    }
    if (os === 'linux' && (first.subarray(0, 4).toString('hex') !== '7f454c46' ||
        first.subarray(8, 11).toString('hex') !== '414902')) {
        throw new Error('AppImage has invalid ELF/AppImage header');
    }
    if (os === 'win' && first.subarray(0, 2).toString('ascii') !== 'MZ') {
        throw new Error('Windows installer has invalid executable header');
    }
    if (os === 'mac' && trailer.subarray(0, 4).toString('ascii') !== 'koly') {
        throw new Error('macOS DMG has invalid UDIF trailer');
    }
    // Stage only actual installers, never unpacked app folders or generated
    // caches. A release publisher may include update manifests separately.
    const output = join(root, 'release-output');
    mkdirSync(output, { recursive: true });
    const publicName = canonicalReleaseAssetName(files[0]);
    copyFileSync(artifact, join(output, publicName));
    console.log('DESKTOP_PACKAGE_VALID=' + os + '-' + arch);
    console.log('DESKTOP_PACKAGE_FILE=' + publicName);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    verifyDesktopPackage(resolve('.'), process.argv[2], process.argv[3]);
}
