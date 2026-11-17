// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { finalizeDesktopRelease } from './finalize-desktop-release.mjs';

const SHA = 'ab'.repeat(20);
function fixture(fn) {
    const root = mkdtempSync(join(tmpdir(), 'automatic-kimai-finalize-'));
    const folder = join(root, 'release-output');
    try {
        mkdirSync(folder);
        ['Automatic-Kimai-linux-x86_64.AppImage', 'Automatic-Kimai-windows-x64-setup.exe',
            'Automatic-Kimai-macos-arm64.dmg', 'Automatic-Kimai-macos-x64.dmg',
            'Automatic.Kimai-0.5.0-arm64.zip', 'Automatic.Kimai-0.5.0-x64.zip',
            'latest.yml', 'SHA256SUMS.txt'].forEach(file => writeFileSync(join(folder, file), 'test'));
        const notesFile = join(root, 'notes.md');
        writeFileSync(notesFile, 'Release notes');
        fn({ folder, notesFile });
    } finally { rmSync(root, { recursive: true, force: true }); }
}
function fake({ draft = null, changed = true, uploadFailure = false } = {}) {
    const calls = [];
    const run = (bin, args) => {
        calls.push([bin, ...args]);
        if (bin === 'gh' && args[0] === 'release' && args[1] === 'view') {
            if (draft === null) throw new Error('404 release missing');
            return draft ? 'true' : 'false';
        }
        if (bin === 'gh' && args[0] === 'release' && args[1] === 'create' && uploadFailure) {
            throw new Error('Upload failed');
        }
        if (bin === 'git' && args[0] === 'rev-parse' && args[1] === 'HEAD') return SHA;
        if (bin === 'git' && args[0] === 'rev-parse') return SHA;
        if (bin === 'git' && args[0] === 'diff' && changed) throw new Error('Changes found');
        if (bin === 'git' && args[0] === 'show-ref') throw new Error('Tag missing');
        return '';
    };
    return { calls, run };
}
const action = (calls, command, subcommand) => calls.filter(c => c[0] === command && c[1] === subcommand);

test('stable release stamps tag commit and publishes only after uploads', () => fixture(({folder,notesFile}) => {
    const { calls, run } = fake();
    const result = finalizeDesktopRelease({ folder, notesFile, tag:'v0.5.0', version:'0.5.0', baseSha:SHA, run });
    assert.equal(result.files, 8);
    assert.equal(action(calls,'git','push').length, 1);
    assert.equal(action(calls,'gh','release').filter(c => c[2] === 'create').length, 1);
    assert.deepEqual(calls.at(-1).slice(-2), ['--draft=false', '--latest=true']);
    assert.equal(calls.some(c => c.includes('commit') && c.includes('-s')), true);
}));
test('published release cannot be modified and no tag is pushed', () => fixture(({folder,notesFile}) => {
    const { calls,run } = fake({draft:false});
    assert.throws(() => finalizeDesktopRelease({ folder, notesFile, tag:'v0.5.0', version:'0.5.0', baseSha:SHA, run }), /already exists/);
    assert.equal(action(calls,'git','push').length, 0);
}));
test('a failed asset upload never publishes the release', () => fixture(({folder,notesFile}) => {
    const { calls,run } = fake({uploadFailure:true});
    assert.throws(() => finalizeDesktopRelease({ folder, notesFile, tag:'v0.5.0', version:'0.5.0', baseSha:SHA, run }), /Upload failed/);
    assert.equal(calls.some(c => c.includes('--draft=false')), false);
}));
test('draft recovery uploads and finalizes without altering its tag', () => fixture(({folder,notesFile}) => {
    const { calls,run } = fake({draft:true});
    const result = finalizeDesktopRelease({ folder, notesFile, tag:'v0.5.0', version:'0.5.0', run });
    assert.equal(result.repairedDraft, true);
    assert.equal(action(calls,'git','push').length, 0);
    assert.equal(calls.some(c => c.includes('upload') && c.includes('--clobber')), true);
    assert.deepEqual(calls.at(-1).slice(-2), ['--draft=false', '--latest=true']);
}));
test('bad tag fails before invoking external commands', () => fixture(({folder,notesFile}) => {
    const { calls,run } = fake();
    assert.throws(() => finalizeDesktopRelease({ folder, notesFile, tag:'v0.5.1', version:'0.5.0', baseSha:SHA, run }), /disagree/);
    assert.equal(calls.length, 0);
}));
