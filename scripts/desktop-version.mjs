#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
// The Electron package is the single manually maintained desktop version.
import { appendFileSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROJECT_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SEMVER = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/;

export function readDesktopVersion(root = PROJECT_ROOT) {
    const electron = join(root, 'nativephp', 'electron');
    const pkg = JSON.parse(readFileSync(join(electron, 'package.json'), 'utf8'));
    const lock = JSON.parse(readFileSync(join(electron, 'package-lock.json'), 'utf8'));
    if (typeof pkg.version !== 'string' || !SEMVER.test(pkg.version)) {
        throw new Error('Invalid desktop package version');
    }
    if (pkg.version !== lock.version || pkg.version !== lock.packages?.['']?.version ||
        pkg.name !== lock.name || pkg.name !== lock.packages?.['']?.name) {
        throw new Error('Electron package and lockfile versions must agree');
    }
    return pkg.version;
}

export function assertReleaseTag(version, refType, refName) {
    if (refType === 'tag' && refName !== `v${version}`) {
        throw new Error(`Release tag ${refName} does not match desktop package version ${version}`);
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const version = readDesktopVersion();
        assertReleaseTag(version, process.env.GITHUB_REF_TYPE, process.env.GITHUB_REF_NAME);
        if (process.argv[2] === '--github-env') {
            if (!process.env.GITHUB_ENV) throw new Error('GITHUB_ENV is required');
            appendFileSync(process.env.GITHUB_ENV, `NATIVEPHP_APP_VERSION=${version}\n`);
        } else if (process.argv.length > 2) {
            throw new Error('Usage: desktop-version.mjs [--github-env]');
        } else {
            console.log(version);
        }
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
