#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-or-later
// Keep first-party workflow actions on supported Node.js 24 runtimes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const workflows = join(root, '.github', 'workflows');
const minimumNode24Majors = Object.freeze({
  checkout: 5,
  'setup-node': 5,
  'upload-artifact': 6,
  'download-artifact': 7,
});

function outdatedFirstPartyActions(text) {
  const matches = text.matchAll(/\buses:\s*actions\/(checkout|setup-node|upload-artifact|download-artifact)@v(\d+)\b/g);
  return [...matches].filter((match) => Number(match[2]) < minimumNode24Majors[match[1]])
    .map((match) => match[0]);
}

test('detects legacy Node.js 20 action runtimes without flagging supported ones', () => {
  assert.deepEqual(outdatedFirstPartyActions(`
    - uses: actions/checkout@v4
    - uses: actions/setup-node@v4
    - uses: actions/upload-artifact@v5
    - uses: actions/download-artifact@v6
  `), [
    'uses: actions/checkout@v4',
    'uses: actions/setup-node@v4',
    'uses: actions/upload-artifact@v5',
    'uses: actions/download-artifact@v6',
  ]);
  assert.deepEqual(outdatedFirstPartyActions(`
    - uses: actions/checkout@v5
    - uses: actions/setup-node@v5
    - uses: actions/upload-artifact@v6
    - uses: actions/download-artifact@v7
  `), []);
});

test('all workflows use Node.js 24-compatible first-party actions', () => {
  const files = readdirSync(workflows).filter((file) => /\.ya?ml$/.test(file));
  assert.ok(files.length >= 3, 'Expected the repository workflows to be present');
  for (const file of files) {
    const content = readFileSync(join(workflows, file), 'utf8');
    assert.deepEqual(outdatedFirstPartyActions(content), [], `Outdated action runtime in ${file}`);
  }
});

test('release workflow retains all installer architectures and artifact safety options', () => {
  const release = readFileSync(join(workflows, 'desktop-release.yml'), 'utf8');
  assert.match(release, /runner:\s*macos-15\s*\n\s*target:\s*mac\s*\n\s*arch:\s*arm64/);
  assert.match(release, /uses:\s*actions\/upload-artifact@v6/);
  assert.match(release, /if-no-files-found:\s*error/);
  assert.match(release, /uses:\s*actions\/download-artifact@v7/);
  assert.match(release, /merge-multiple:\s*true/);
});
