#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
// Finalize already-validated release artifacts. The command runner is
// injectable so tags and GitHub publishing can be tested without network.
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readDesktopVersion } from './desktop-version.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const TAG = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/;
function command(bin, args, quiet = false) {
    return execFileSync(bin, args, {
        cwd: ROOT, encoding: 'utf8', stdio: quiet ? ['ignore', 'pipe', 'pipe'] : 'inherit',
    }) || '';
}
function outcome(run, bin, args) {
    try { return { exists: true, value: String(run(bin, args, true)).trim() }; }
    catch { return { exists: false, value: '' }; }
}

export function finalizeDesktopRelease({ folder, tag, version, notesFile, baseSha = '', run = command }) {
    if (!TAG.test(tag) || tag !== 'v' + version) throw new Error('Tag and package version disagree');
    if (!existsSync(notesFile) || !statSync(notesFile).isFile()) throw new Error('Release notes are missing');
    const names = readdirSync(folder).sort();
    if (!names.includes('SHA256SUMS.txt') || names.length < 7 ||
        names.some(name => !/^[A-Za-z0-9._-]+$/.test(name) || !statSync(join(folder, name)).isFile())) {
        throw new Error('Release artifacts have not been validated');
    }
    const files = names.map(name => join(folder, name));
    const existing = outcome(run, 'gh', ['release', 'view', tag, '--json', 'isDraft', '--jq', '.isDraft']);
    if (existing.exists && existing.value !== 'true') throw new Error('Published release already exists');
    if (baseSha) {
        if (!/^[0-9a-f]{40}$/.test(baseSha) ||
            String(run('git', ['rev-parse', 'HEAD'], true)).trim() !== baseSha) {
            throw new Error('Release source HEAD changed');
        }
        const changed = !outcome(run, 'git', [
            'diff', '--quiet', '--', 'nativephp/electron/package.json', 'nativephp/electron/package-lock.json',
        ]).exists;
        if (changed) {
            run('git', ['add', '--', 'nativephp/electron/package.json', 'nativephp/electron/package-lock.json']);
            run('git', ['-c', 'user.name=github-actions[bot]',
                '-c', 'user.email=41898282+github-actions[bot]@users.noreply.github.com',
                'commit', '-s', '-m', 'chore(release): ' + tag]);
        }
        if (outcome(run, 'git', ['show-ref', '--verify', '--quiet', 'refs/tags/' + tag]).exists) {
            throw new Error('Release tag already exists; never overwrite tags');
        }
        run('git', ['tag', tag]);
        run('git', ['push', 'origin', 'refs/tags/' + tag]);
    } else if (!outcome(run, 'git', ['rev-parse', '--verify', 'refs/tags/' + tag]).exists) {
        throw new Error('Manual recovery requires an existing release tag');
    }
    const isPreview = tag.includes('-');
    if (existing.exists) {
        // A draft may be left behind by a failed upload; published releases
        // must never be overwritten. Only repair a draft for the same tag.
        run('gh', ['release', 'upload', tag, ...files, '--clobber']);
        run('gh', ['release', 'edit', tag, '--notes-file', notesFile]);
    } else {
        run('gh', ['release', 'create', tag, ...files, '--verify-tag', '--draft',
            '--title', 'Automatic Kimai ' + tag + (isPreview ? ' (prévia)' : ''),
            ...(isPreview ? ['--prerelease'] : []), '--notes-file', notesFile]);
    }
    run('gh', ['release', 'edit', tag, '--draft=false', '--latest=' + String(!isPreview)]);
    return { tag, files: files.length, repairedDraft: existing.exists };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const [folder, tag, ...options] = process.argv.slice(2);
        if (!folder || !tag || options.length > 2 ||
            (options.length > 0 && options[0] !== '--main-source')) {
            throw new Error('Usage: finalize-desktop-release.mjs <folder> <tag> [--main-source <sha>]');
        }
        const baseSha = options[0] === '--main-source' ? options[1] : '';
        if (options.length && !baseSha) throw new Error('Main source SHA is required');
        const result = finalizeDesktopRelease({ folder: resolve(folder), tag,
            version: readDesktopVersion(), notesFile: join(ROOT, 'release-notes.md'), baseSha });
        console.log('PUBLISHED_DESKTOP_RELEASE=' + result.tag);
    } catch (error) { console.error(error.message); process.exitCode = 1; }
}
