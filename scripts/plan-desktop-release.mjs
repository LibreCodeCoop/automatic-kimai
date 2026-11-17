#!/usr/bin/env node
// SPDX-License-Identifier: GPL-3.0-or-later
// Determine a stable desktop release using Conventional Commits since the
// preceding release. No tags, version files or releases are written here.
import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { assertReleaseTag, readDesktopVersion } from './desktop-version.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const STABLE_TAG = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/;
const MANUAL_TAG = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?$/;
const LEVEL = { none: 0, patch: 1, minor: 2, major: 3 };

export function parseStableTag(tag) {
    const match = STABLE_TAG.exec(tag);
    return match ? match.slice(1).map(Number) : null;
}
export function latestStableTag(tags) {
    const stable = tags.map(tag => ({ tag, parts: parseStableTag(tag) })).filter(item => item.parts);
    stable.sort((a, b) => {
        for (let i = 0; i < 3; i += 1) {
            if (a.parts[i] !== b.parts[i]) return b.parts[i] - a.parts[i];
        }
        return 0;
    });
    return stable[0]?.tag ?? null;
}
export function conventionalChange(message) {
    const header = message.split(/\r?\n/, 1)[0].trim();
    const match = /^([a-z][a-z0-9-]*)(?:\([a-zA-Z0-9._/-]+\))?(!)?:\s+(.+)$/.exec(header);
    if (!match) return { level: 'none', header };
    const breaking = Boolean(match[2]) || /^BREAKING(?: CHANGE|-CHANGE):\s*\S/im.test(message);
    const level = breaking ? 'major' : match[1] === 'feat' ? 'minor' :
        ['fix', 'perf', 'revert'].includes(match[1]) ? 'patch' : 'none';
    return { level, header, type: match[1] };
}
export function nextVersion(version, level) {
    const parts = parseStableTag('v' + version);
    if (!parts) throw new Error('Invalid previous stable release version: ' + version);
    if (level === 'major') return (parts[0] + 1) + '.0.0';
    if (level === 'minor') return parts[0] + '.' + (parts[1] + 1) + '.0';
    if (level === 'patch') return parts[0] + '.' + parts[1] + '.' + (parts[2] + 1);
    if (level === 'none') return version;
    throw new Error('Unsupported release level');
}
export function releaseDecision(previousTag, messages) {
    const previous = parseStableTag(previousTag);
    if (!previous) throw new Error('A previous stable vX.Y.Z tag is required');
    const relevant = messages.map(conventionalChange).filter(change => change.level !== 'none');
    const level = relevant.reduce((maximum, change) =>
        LEVEL[change.level] > LEVEL[maximum] ? change.level : maximum, 'none');
    const version = nextVersion(previous.join('.'), level);
    return { publish: level !== 'none', version, tag: level === 'none' ? '' : 'v' + version, level, changes: relevant };
}
export function parseGitMessages(output) {
    return output.split('\0').map(message => message.trim()).filter(Boolean);
}
function git(...args) {
    return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim();
}
export function planFromGit() {
    const previousTag = latestStableTag(git('tag', '--list', 'v*').split(/\r?\n/).filter(Boolean));
    if (!previousTag) throw new Error('Cannot automate releases without an existing stable tag');
    const baseSha = git('merge-base', previousTag, 'HEAD');
    const tagSha = git('rev-parse', previousTag + '^{commit}');
    const parents = git('rev-list', '--parents', '-n', '1', previousTag).split(/\s+/);
    // Auto-release tags may point to a version-only commit whose parent is
    // main. Do not include that synthetic release commit in the next range.
    if (baseSha !== tagSha && (parents.length !== 2 || baseSha !== parents[1])) {
        throw new Error('Latest release tag does not belong to this main branch history');
    }
    const messages = parseGitMessages(git('log', '--format=%B%x00', baseSha + '..HEAD'));
    return { ...releaseDecision(previousTag, messages), baseSha, previousTag };
}
export function releaseNotes(template, messages, tag, previousTag) {
    const entries = messages.map(conventionalChange).filter(change => change.level !== 'none');
    const sections = [
        ['Mudanças incompatíveis', entries.filter(change => change.level === 'major')],
        ['Funcionalidades', entries.filter(change => change.level === 'minor')],
        ['Correções e desempenho', entries.filter(change => change.level === 'patch')],
    ].filter(([, items]) => items.length).map(([title, items]) =>
        '### ' + title + '\n\n' + items.map(item => '- ' + item.header).join('\n'));
    return template.trim() + '\n\n## ' + tag + ' — alterações desde ' + previousTag + '\n\n' + sections.join('\n\n') + '\n';
}
function run() {
    const [mode, ...args] = process.argv.slice(2);
    if (mode === '--notes') {
        if (args.length !== 3) throw new Error('Usage: --notes <base-sha> <head-sha> <tag>');
        const [baseSha, headSha, tag] = args;
        if (!parseStableTag(tag) || !/^[0-9a-f]{40}$/.test(baseSha) || !/^[0-9a-f]{40}$/.test(headSha)) {
            throw new Error('Invalid release notes inputs');
        }
        const messages = parseGitMessages(git('log', '--format=%B%x00', baseSha + '..' + headSha));
        const previousTag = latestStableTag(git('tag', '--list', 'v*').split(/\r?\n/).filter(Boolean));
        const template = readFileSync(join(ROOT, '.github', 'RELEASE_NOTES.md'), 'utf8');
        process.stdout.write(releaseNotes(template, messages, tag, previousTag));
        return;
    }
    if (mode !== '--github-output') throw new Error('Usage: --github-output | --notes <base> <head> <tag>');
    const { GITHUB_EVENT_NAME: event, GITHUB_REF_TYPE: refType, GITHUB_REF_NAME: refName,
        GITHUB_REF: ref, GITHUB_OUTPUT: output, RELEASE_TAG_INPUT: requestedTag } = process.env;
    if (!output) throw new Error('GITHUB_OUTPUT is required');
    const current = readDesktopVersion();
    let result;
    if (event === 'push' && ref === 'refs/heads/main') {
        result = planFromGit();
        if (result.publish && parseStableTag('v' + current) && parseStableTag('v' + result.version) &&
            latestStableTag(['v' + current, 'v' + result.version]) === 'v' + current &&
            current !== result.version) {
            throw new Error('Desktop package version ' + current + ' is ahead of calculated release ' + result.version);
        }
    } else if ((event === 'push' && refType === 'tag') ||
        (event === 'workflow_dispatch' && requestedTag)) {
        const tag = event === 'workflow_dispatch' ? requestedTag : refName;
        if (!MANUAL_TAG.test(tag)) throw new Error('Invalid desktop release tag');
        assertReleaseTag(current, 'tag', tag);
        result = { publish: true, tag, version: current, baseSha: '', previousTag: '' };
    } else {
        result = { publish: false, tag: '', version: current, baseSha: '', previousTag: '' };
    }
    appendFileSync(output, 'publish=' + result.publish + '\nversion=' + result.version +
        '\ntag=' + result.tag + '\nbase_sha=' + result.baseSha + '\nprevious_tag=' + result.previousTag + '\n');
    console.log(result.publish ? 'Planned desktop release ' + result.tag : 'No new desktop release required');
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try { run(); } catch (error) { console.error(error.message); process.exitCode = 1; }
}
