// SPDX-License-Identifier: GPL-3.0-or-later
// Desktop-only, consent-based updates from the official public GitHub release.
// No remote URL or renderer-supplied path can be used as an installer source.
const OWNER = 'LibreCodeCoop';
const REPOSITORY = 'automatic-kimai';
export const RELEASES_URL = `https://github.com/${OWNER}/${REPOSITORY}/releases/latest`;
export const API_URL = `https://api.github.com/repos/${OWNER}/${REPOSITORY}/releases/latest`;
export const UPDATE_CHANNEL = 'automatic-kimai:update-status';
export const UPDATE_OPERATIONS = Object.freeze({
    status: 'automatic-kimai:update-status-get',
    check: 'automatic-kimai:update-check',
    download: 'automatic-kimai:update-download',
    restart: 'automatic-kimai:update-restart',
    open: 'automatic-kimai:update-open-releases',
});

const PLATFORMS = {
    linux: { name: 'Automatic-Kimai-linux-x86_64.AppImage', manifest: 'latest-linux.yml' },
    win32: { name: 'Automatic-Kimai-windows-x64-setup.exe', manifest: 'latest.yml' },
    darwin: { name: null, manifest: 'latest-mac.yml' },
};

export function compareVersions(a, b) {
    const parse = version => {
        const match = /^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/.exec(version);
        if (!match) return null;
        return [match.slice(1, 4).map(Number), match[4] || ''];
    };
    const first = parse(a);
    const second = parse(b);
    if (!first || !second) return null;
    for (let i = 0; i < 3; i += 1) {
        if (first[0][i] !== second[0][i]) return Math.sign(first[0][i] - second[0][i]);
    }
    if (first[1] === second[1]) return 0;
    if (!first[1]) return 1;
    if (!second[1]) return -1;
    return first[1].localeCompare(second[1], 'en', { numeric: true });
}

export function isLocalTopFrame(event, localAppPort) {
    try {
        if (!Number.isInteger(localAppPort) || localAppPort < 1024 || localAppPort > 65535 ||
            !event?.senderFrame || event.senderFrame !== event.sender?.mainFrame) return false;
        const url = new URL(event.senderFrame.url);
        return url.protocol === 'http:' &&
            (url.hostname === '127.0.0.1' || url.hostname === 'localhost') &&
            Number(url.port) === localAppPort &&
            !url.username && !url.password;
    } catch {
        return false;
    }
}

export function describeUpdate(release, currentVersion, platform, arch, canUseMacUpdates = false, appImage = false) {
    if (!release || release.draft || release.prerelease ||
        typeof release.tag_name !== 'string' || !/^v\d+\.\d+\.\d+$/.test(release.tag_name)) {
        throw new Error('Invalid stable release');
    }
    const version = release.tag_name.replace(/^v/, '');
    const comparison = compareVersions(version, currentVersion);
    if (comparison === null) throw new Error('Invalid release version');
    if (comparison <= 0) return { available: false, version: currentVersion };
    const assets = new Set((Array.isArray(release.assets) ? release.assets : [])
        .map(asset => typeof asset?.name === 'string' ? asset.name : ''));
    const target = PLATFORMS[platform];
    const platformMatches = (platform === 'darwin' && ['arm64', 'x64'].includes(arch)) ||
        (platform === 'linux' && arch === 'x64') ||
        (platform === 'win32' && arch === 'x64');
    const expectedName = platform === 'darwin' ? `Automatic-Kimai-macos-${arch}.dmg` : target?.name;
    const foundPackage = platformMatches && expectedName && assets.has(expectedName);
    const foundManifest = target?.manifest && assets.has(target.manifest);
    const foundMacZip = platform === 'darwin' && assets.has(`Automatic.Kimai-${version}-${arch}.zip`);
    const automatic = Boolean(foundPackage && foundManifest &&
        (platform === 'darwin' ? canUseMacUpdates && foundMacZip :
            platform === 'linux' ? appImage : true));
    return {
        available: true,
        version,
        mode: automatic ? 'automatic' : 'manual',
        message: !foundPackage ? 'Ainda não há instalador para esta plataforma.' :
            platform === 'darwin' && !canUseMacUpdates ?
                'Para atualizar automaticamente no macOS, o aplicativo precisa de assinatura e notarização Apple.' :
            platform === 'linux' && !appImage ?
                'A atualização integrada exige que o aplicativo seja executado como AppImage.' :
            !automatic ? 'A atualização integrada não está disponível nesta versão. Use o download oficial.' : '',
    };
}

export class DesktopUpdateManager {
    constructor({ app, updater, fetchRelease, openExternal, broadcast, platform, arch, appPort, canUseMacUpdates = false, appImage = false }) {
        this.app = app;
        this.updater = updater;
        this.fetchRelease = fetchRelease;
        this.openExternal = openExternal;
        this.broadcast = broadcast;
        this.platform = platform;
        this.arch = arch;
        this.appPort = appPort;
        this.canUseMacUpdates = canUseMacUpdates;
        this.appImage = appImage;
        this.busy = false;
        this.status = {
            phase: 'idle',
            currentVersion: app.getVersion(),
            packaged: Boolean(app.isPackaged),
            version: null,
            mode: 'manual',
            progress: 0,
            message: '',
            releasesUrl: RELEASES_URL,
        };
        updater.autoDownload = false;
        updater.autoInstallOnAppQuit = false;
        updater.allowPrerelease = false;
        if ('autoInstallEvent' in updater) updater.autoInstallEvent = 'manual';
        updater.setFeedURL({ provider: 'github', owner: OWNER, repo: REPOSITORY, releaseType: 'release' });

        updater.on('download-progress', ({ percent }) => {
            if (this.status.phase === 'downloading') {
                this.change({ progress: Math.max(0, Math.min(100, Number(percent) || 0)) });
            }
        });
        updater.on('update-downloaded', ({ version }) => {
            if (this.status.phase === 'downloading' && version === this.status.version) {
                this.change({ phase: 'ready', progress: 100, message: 'Atualização pronta para instalar.' });
            }
        });
        updater.on('error', () => {
            if (this.status.phase === 'downloading') {
                this.change({ phase: 'error', mode: 'manual', message: 'Falha no download. Tente novamente ou use a página oficial.' });
            }
        });
    }

    snapshot() { return { ...this.status }; }

    change(next) {
        this.status = { ...this.status, ...next };
        this.broadcast(this.snapshot());
        return this.snapshot();
    }

    async check() {
        if (!this.app.isPackaged) {
            return this.change({ phase: 'unavailable', message: 'A verificação é disponibilizada no aplicativo instalado.' });
        }
        if (this.busy || ['downloading', 'ready', 'installing'].includes(this.status.phase)) return this.snapshot();
        this.busy = true;
        this.change({ phase: 'checking', message: '' });
        try {
            const release = await this.fetchRelease(API_URL);
            const result = describeUpdate(release, this.app.getVersion(), this.platform,
                this.arch, this.canUseMacUpdates, this.appImage);
            return this.change(result.available ?
                { phase: 'available', version: result.version, mode: result.mode, message: result.message, progress: 0 } :
                { phase: 'current', version: this.app.getVersion(), mode: 'manual', message: 'Você já está usando a versão mais recente.', progress: 0 });
        } catch {
            return this.change({ phase: 'error', mode: 'manual', message: 'Não foi possível consultar as versões. Verifique sua conexão e tente novamente.' });
        } finally {
            this.busy = false;
        }
    }

    async download() {
        if (!this.app.isPackaged || this.busy || this.status.phase !== 'available' ||
            this.status.mode !== 'automatic' || !this.status.version) return this.snapshot();
        this.busy = true;
        const target = this.status.version;
        this.change({ phase: 'downloading', progress: 0, message: 'Preparando o download…' });
        try {
            const result = await this.updater.checkForUpdates();
            if (result?.updateInfo?.version !== target) throw new Error('Release changed');
            await this.updater.downloadUpdate();
            // The updater emits update-downloaded only after verifying the payload.
            return this.snapshot();
        } catch {
            return this.change({ phase: 'error', mode: 'manual', message: 'Falha na verificação ou download da atualização. Use o download oficial.' });
        } finally {
            this.busy = false;
        }
    }

    restart() {
        if (!this.app.isPackaged || this.status.phase !== 'ready' || this.status.mode !== 'automatic') return false;
        this.change({ phase: 'installing', message: 'Reiniciando para instalar a atualização…' });
        this.updater.quitAndInstall(false, true);
        return true;
    }

    async openReleases() {
        await this.openExternal(RELEASES_URL);
        return this.snapshot();
    }

    registerIpc(ipcMain) {
        const guarded = handler => (event) => {
            if (!isLocalTopFrame(event, this.appPort())) throw new Error('Updater request denied');
            return handler();
        };
        ipcMain.handle(UPDATE_OPERATIONS.status, guarded(() => this.snapshot()));
        ipcMain.handle(UPDATE_OPERATIONS.check, guarded(() => this.check()));
        ipcMain.handle(UPDATE_OPERATIONS.download, guarded(() => this.download()));
        ipcMain.handle(UPDATE_OPERATIONS.restart, guarded(() => this.restart()));
        ipcMain.handle(UPDATE_OPERATIONS.open, guarded(() => this.openReleases()));
    }
}
