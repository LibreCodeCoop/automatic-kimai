// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, copyFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { conventionalChange, latestStableTag, nextVersion, parseGitMessages, releaseDecision, releaseNotes } from './plan-desktop-release.mjs';

test('highest stable SemVer tag excludes prereleases and invalid tags', () => {
    assert.equal(latestStableTag(['v0.4.9', 'v0.10.0', 'v1.0.0-rc.1', 'v0.9.10', 'anything']), 'v0.10.0');
    assert.equal(latestStableTag(['v0.4.0-beta.1', 'unrelated']), null);
});
test('features, fixes, performance, breaking changes and nonreleasing commits', () => {
    assert.equal(conventionalChange('feat(ui): new panel').level, 'minor');
    assert.equal(conventionalChange('fix: bad cache').level, 'patch');
    assert.equal(conventionalChange('perf: faster startup').level, 'patch');
    assert.equal(conventionalChange('refactor!: remove legacy API').level, 'major');
    assert.equal(conventionalChange('feat(api): update\n\nBREAKING CHANGE: incompatible').level, 'major');
    for (const message of ['docs: README', 'test: coverage', 'chore(release): v0.4.1', 'Merge pull request #52']) {
        assert.equal(conventionalChange(message).level, 'none');
    }
});
test('highest bump prevails; docs-only changes do not release', () => {
    assert.equal(releaseDecision('v0.4.1', ['docs: usage', 'test: browser']).publish, false);
    assert.equal(releaseDecision('v0.4.1', ['fix: bug']).tag, 'v0.4.2');
    assert.equal(releaseDecision('v0.4.1', ['fix: bug', 'feat: new']).tag, 'v0.5.0');
    assert.equal(releaseDecision('v0.4.1', ['feat!: change old API']).tag, 'v1.0.0');
});
test('invalid baselines fail, and versions follow SemVer', () => {
    assert.throws(() => releaseDecision('v0.4.0-rc.1', ['feat: new']), /previous stable/);
    assert.equal(nextVersion('0.9.11', 'minor'), '0.10.0');
});
test('NUL-delimited Git messages produce readable release notes', () => {
    const messages = parseGitMessages('feat: first\n\nSigned-off-by: user\0fix: second\n\0chore: build\0');
    assert.equal(messages.length, 3);
    const notes = releaseNotes('Safety notes.\n', messages, 'v0.5.0', 'v0.4.0');
    assert.match(notes, /Safety notes/);
    assert.match(notes, /- feat: first/);
    assert.match(notes, /- fix: second/);
    assert.doesNotMatch(notes, /chore: build/);
});
test('real Git: no release for docs-only merge; tagged release sidecar does not double release', () => {
    const root = mkdtempSync(join(tmpdir(), 'kimai-release-git-'));
    const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim();
    try {
        mkdirSync(join(root, 'scripts'));
        mkdirSync(join(root, 'nativephp', 'electron'), { recursive: true });
        copyFileSync(new URL('./plan-desktop-release.mjs', import.meta.url), join(root, 'scripts', 'plan-desktop-release.mjs'));
        copyFileSync(new URL('./desktop-version.mjs', import.meta.url), join(root, 'scripts', 'desktop-version.mjs'));
        const pkg = version => JSON.stringify({ name: 'automatic-kimai', version });
        const lock = version => JSON.stringify({ name: 'automatic-kimai', version,
            packages: { '': { name: 'automatic-kimai', version } } });
        const file = name => join(root, 'nativephp', 'electron', name);
        writeFileSync(file('package.json'), pkg('0.4.0'));
        writeFileSync(file('package-lock.json'), lock('0.4.0'));
        git('init', '-b', 'main');
        git('config', 'user.email', 'tester@example.test');
        git('config', 'user.name', 'Test');
        git('add', '.');
        git('commit', '-m', 'chore: initial release');
        git('tag', 'v0.4.0');
        // Source package may be ahead of the last tag without triggering a docs-only release.
        writeFileSync(file('package.json'), pkg('0.4.1'));
        writeFileSync(file('package-lock.json'), lock('0.4.1'));
        git('add', '.');
        git('commit', '-m', 'docs: adjust download table');
        const output = join(root, 'outputs');
        const env = { ...process.env, GITHUB_EVENT_NAME: 'push', GITHUB_REF_TYPE: 'branch',
            GITHUB_REF_NAME: 'main', GITHUB_REF: 'refs/heads/main', GITHUB_OUTPUT: output };
        const run = () => {
            writeFileSync(output, '');
            return execFileSync('node', ['scripts/plan-desktop-release.mjs', '--github-output'],
                { cwd: root, env, encoding: 'utf8' });
        };
        run();
        assert.match(readFileSync(output, 'utf8'), /publish=false/);
        writeFileSync(join(root, 'feature.txt'), 'feature');
        git('add', '.');
        git('commit', '-m', 'feat: useful feature');
        const originalMain = git('rev-parse', 'HEAD');
        run();
        assert.match(readFileSync(output, 'utf8'), /tag=v0\.5\.0/);
        git('checkout', '--detach', '-q');
        writeFileSync(file('package.json'), pkg('0.5.0'));
        writeFileSync(file('package-lock.json'), lock('0.5.0'));
        git('add', '.');
        git('commit', '-m', 'chore(release): v0.5.0');
        git('tag', 'v0.5.0');
        git('checkout', '-q', 'main');
        assert.equal(git('rev-parse', 'HEAD'), originalMain);
        run();
        assert.match(readFileSync(output, 'utf8'), /publish=false/);
        writeFileSync(join(root, 'fix.txt'), 'fix');
        git('add', '.');
        git('commit', '-m', 'fix: regression');
        run();
        assert.match(readFileSync(output, 'utf8'), /tag=v0\.5\.1/);
        assert.match(readFileSync(output, 'utf8'), /publish=true/);
    } finally { rmSync(root, { force: true, recursive: true }); }
});
