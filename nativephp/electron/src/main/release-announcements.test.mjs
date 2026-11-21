// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { ReleaseAnnouncementTracker, FileReleaseAnnouncementStorage, compareStableVersions } from './release-announcements.mjs';

function memoryStorage() {
    let value = null;
    return { read: () => value, write: next => { value = structuredClone(next); } };
}
const tracker = (storage, version, packaged = true) =>
    new ReleaseAnnouncementTracker({ storage, version, packaged });

test('first packaged launch shows release notes once, including legacy upgrades', () => {
    const storage = memoryStorage();
    assert.deepEqual(tracker(storage, '0.8.1').status(), { version: '0.8.1', pending: true });
    assert.equal(tracker(storage, '0.8.1').status().pending, true);
    tracker(storage, '0.8.1').acknowledge();
    assert.equal(tracker(storage, '0.8.1').status().pending, false);
});
test('upgrade persists across restarts until explicit acknowledgment', () => {
    const storage = memoryStorage();
    tracker(storage, '0.8.1').acknowledge();
    const upgraded = tracker(storage, '0.8.2');
    assert.equal(upgraded.status().pending, true);
    assert.equal(tracker(storage, '0.8.2').status().pending, true);
    assert.equal(upgraded.acknowledge().pending, false);
    assert.equal(tracker(storage, '0.8.2').status().pending, false);
    assert.equal(tracker(storage, '0.9.0').status().pending, true);
});
test('preview builds and downgrades do not trigger or rewrite notifications', () => {
    const storage = memoryStorage();
    tracker(storage, '1.0.0').acknowledge();
    assert.equal(tracker(storage, '0.9.9').status().pending, false);
    assert.equal(tracker(storage, '1.0.0').status().pending, false);
    const prior = storage.read();
    assert.equal(tracker(storage, '1.1.0', false).status().pending, false);
    assert.deepEqual(storage.read(), prior);
    assert.equal(tracker(storage, '1.1.0-dev').status().pending, false);
});
test('new release supersedes old pending notes', () => {
    const storage = memoryStorage();
    tracker(storage, '1.0.0');
    tracker(storage, '1.1.0');
    assert.equal(tracker(storage, '1.2.0').status().pending, true);
    assert.equal(storage.read().pendingVersion, '1.2.0');
});
test('unwritable metadata fails safely without claiming acknowledgment', () => {
    const storage = { read: () => { throw new Error('failed'); }, write: () => {} };
    assert.equal(tracker(storage, '1.0.0').status().pending, false);
    const writable = memoryStorage();
    tracker(writable, '1.0.0');
    writable.write = () => { throw new Error('disk full'); };
    assert.equal(tracker(writable, '1.0.0').acknowledge().pending, true);
});
test('file persistence is scoped to the device and survives a restart', () => {
    const dir = mkdtempSync(join(tmpdir(), 'automatic-kimai-release-test-'));
    try {
        const storage = new FileReleaseAnnouncementStorage(dir);
        tracker(storage, '0.8.1').acknowledge();
        assert.equal(tracker(new FileReleaseAnnouncementStorage(dir), '0.8.2').status().pending, true);
        tracker(storage, '0.8.2').acknowledge();
        assert.equal(tracker(new FileReleaseAnnouncementStorage(dir), '0.8.2').status().pending, false);
    } finally { rmSync(dir, { recursive: true, force: true }); }
});
test('stable versions compare numerically', () => {
    assert.equal(compareStableVersions('0.10.0', '0.9.9'), 1);
    assert.equal(compareStableVersions('1.0.0', '1.0.0'), 0);
    assert.equal(compareStableVersions('bad', '1.0.0'), null);
});
