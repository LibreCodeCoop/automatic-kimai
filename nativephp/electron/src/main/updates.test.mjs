// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { compareVersions, describeUpdate, isLocalTopFrame, DesktopUpdateManager, UPDATE_OPERATIONS } from './updates.mjs';

function release(version, assets = [
    'Automatic-Kimai-linux-x86_64.AppImage', 'Automatic-Kimai-windows-x64-setup.exe',
    'Automatic-Kimai-macos-arm64.dmg', 'Automatic-Kimai-macos-x64.dmg',
    'latest-linux.yml', 'latest.yml', 'latest-mac.yml', `Automatic.Kimai-${version}-arm64.zip`,
    `Automatic.Kimai-${version}-x64.zip`,
]) {
    return { tag_name: `v${version}`, draft: false, prerelease: false,
        assets: assets.map(name => ({ name })) };
}

function harness({ version = '0.4.0', platform = 'win32', packaged = true, releases = release('0.5.0'), macSigned = false, appImage = true } = {}) {
    const updater = new EventEmitter();
    updater.setFeedURL = opts => { updater.feed = opts; };
    updater.checkForUpdates = async () => ({ updateInfo: { version: '0.5.0' } });
    updater.downloadUpdate = async () => {
        updater.emit('download-progress', { percent: 36.5 });
        updater.emit('update-downloaded', { version: '0.5.0' });
    };
    updater.quitAndInstall = () => { updater.installed = true; };
    let opens = 0;
    const statuses = [];
    const manager = new DesktopUpdateManager({
        app: { getVersion: () => version, isPackaged: packaged },
        updater,
        platform,
        appPort: () => 1234,
        arch: platform === 'darwin' ? 'arm64' : 'x64',
        canUseMacUpdates: macSigned,
        appImage,
        fetchRelease: async () => releases,
        openExternal: async () => { opens++; },
        broadcast: status => statuses.push(status),
    });
    return { manager, updater, statuses, opened: () => opens };
}

test('compares stable and prerelease semver, refusing invalid data', () => {
    assert.equal(compareVersions('0.5.0', 'v0.4.0'), 1);
    assert.equal(compareVersions('0.4.0', '0.4.0-alpha.2'), 1);
    assert.equal(compareVersions('0.4.0', '0.4.0'), 0);
    assert.equal(compareVersions('not-version', '0.4.0'), null);
});

test('available update is not automatically downloaded or installed', async () => {
    const { manager, updater, statuses } = harness();
    const info = await manager.check();
    assert.equal(info.phase, 'available');
    assert.equal(info.mode, 'automatic');
    assert.equal(updater.autoDownload, false);
    assert.equal(updater.autoInstallOnAppQuit, false);
    assert.equal(updater.installed, undefined);
    assert.equal(statuses.at(-1).version, '0.5.0');
});

test('explicit download shows progress and explicit restart installs', async () => {
    const { manager, updater } = harness();
    assert.equal(manager.restart(), false);
    await manager.check();
    assert.equal((await manager.download()).phase, 'ready');
    assert.equal(manager.snapshot().progress, 100);
    assert.equal(manager.restart(), true);
    assert.equal(updater.installed, true);
});

test('macOS unsigned builds present official download without automatic install', async () => {
    const { manager } = harness({ platform: 'darwin' });
    const state = await manager.check();
    assert.equal(state.phase, 'available');
    assert.equal(state.mode, 'manual');
    assert.match(state.message, /assinatura/);
    assert.equal((await manager.download()).phase, 'available');
});

test('a signed and notarized macOS build can use a published zip update feed', async () => {
    const { manager } = harness({ platform: 'darwin', macSigned: true });
    assert.equal((await manager.check()).mode, 'automatic');
});

test('missing metadata or unsupported AppImage execution offers the manual path', async () => {
    const noMeta = harness({ releases: release('0.5.0', ['Automatic-Kimai-windows-x64-setup.exe']) });
    assert.equal((await noMeta.manager.check()).mode, 'manual');
    const unpacked = harness({ platform: 'linux', appImage: false });
    assert.equal((await unpacked.manager.check()).mode, 'manual');
});

test('no new release and a prerelease cannot trigger a download', async () => {
    const current = harness({ releases: release('0.4.0') });
    assert.equal((await current.manager.check()).phase, 'current');
    const prerelease = harness({ releases: { ...release('0.6.0'), prerelease: true } });
    assert.equal((await prerelease.manager.check()).phase, 'error');
    assert.equal((await prerelease.manager.download()).phase, 'error');
});

test('update version changes between check and download fails closed', async () => {
    const { manager, updater } = harness();
    await manager.check();
    updater.checkForUpdates = async () => ({ updateInfo: { version: '0.6.0' } });
    assert.equal((await manager.download()).phase, 'error');
    assert.equal(updater.installed, undefined);
});

test('manual fallback always uses the hardcoded official releases page', async () => {
    const { manager, opened } = harness();
    await manager.openReleases();
    assert.equal(opened(), 1);
});

test('renderer may invoke only from top-level local app frame', () => {
    const mainFrame = { url: 'http://127.0.0.1:1234/?view=overview' };
    assert.equal(isLocalTopFrame({ senderFrame: mainFrame, sender: { mainFrame } }, 1234), true);
    const external = { url: 'https://evil.example/' };
    assert.equal(isLocalTopFrame({ senderFrame: external, sender: { mainFrame: external } }, 1234), false);
    const iframe = { url: mainFrame.url };
    assert.equal(isLocalTopFrame({ senderFrame: iframe, sender: { mainFrame } }, 1234), false);
    const wrongPort = { url: 'http://127.0.0.1:4321/' };
    assert.equal(isLocalTopFrame({ senderFrame: wrongPort, sender: { mainFrame: wrongPort } }, 1234), false);
    assert.equal(isLocalTopFrame({ senderFrame: mainFrame, sender: { mainFrame } }, 0), false);
});

test('IPC rejects external renderer before running operations', async () => {
    const { manager } = harness();
    const handlers = new Map();
    manager.registerIpc({ handle: (key, fn) => handlers.set(key, fn) });
    const external = { url: 'https://example.com' };
    assert.throws(() => handlers.get(UPDATE_OPERATIONS.check)({
        senderFrame: external, sender: { mainFrame: external },
    }), /denied/);
    assert.equal(handlers.size, 5);
});

test('version metadata identifies packaged builds and development previews without calling GitHub', () => {
    const packaged = harness({version: '0.7.0', packaged: true});
    assert.equal(packaged.manager.snapshot().currentVersion, '0.7.0');
    assert.equal(packaged.manager.snapshot().packaged, true);
    assert.equal(packaged.manager.snapshot().phase, 'idle');
    const preview = harness({version: '0.4.1', packaged: false});
    assert.equal(preview.manager.snapshot().packaged, false);
});

test('development mode never checks remote release or installs', async () => {
    const { manager } = harness({ packaged: false });
    assert.equal((await manager.check()).phase, 'unavailable');
    assert.equal((await manager.download()).phase, 'unavailable');
    assert.equal(manager.restart(), false);
});
