import NativePHP from '#plugin';
import { app, BrowserWindow, shell, ipcMain } from 'electron';
import path from 'path';
import { execFileSync, spawnSync } from 'node:child_process';
import electronUpdater from 'electron-updater';
import { DesktopUpdateManager, UPDATE_CHANNEL, API_URL, RELEASES_URL } from './updates.mjs';
import { createSplash } from './splash.js';
// Inherit User's PATH in Process & ChildProcess
import fixPath from 'fix-path';
fixPath();

const buildPath = path.resolve(import.meta.dirname, import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH);
const defaultIcon = path.join(buildPath, 'icon.png');
const certificate = path.join(buildPath, 'cacert.pem');

const executable = process.platform === 'win32' ? 'php.exe' : 'php';
const phpBinary = path.join(buildPath, 'php', executable);
const appPath = path.join(buildPath, 'app');

/**
 * Electron windows have an isolated cookie jar. Open authorized HTTPS links
 * in the operating system browser so Nextcloud Login Flow v2 can use the
 * user's existing signed-in browser session, instead of Electron's session.
 */
function openInDefaultBrowser(rawUrl) {
    try {
        const url = new URL(rawUrl);
        if (url.protocol !== 'https:' || url.username || url.password) {
            return;
        }
        // Never print a Login Flow URL: its path contains a short-lived token.
        void shell.openExternal(url.toString()).catch(() => {});
    } catch (_error) {
        // Do not open arbitrary schemes or malformed destinations.
    }
}

function isAppNavigation(rawUrl) {
    try {
        const url = new URL(rawUrl);
        return url.protocol === 'http:' &&
            (url.hostname === '127.0.0.1' || url.hostname === 'localhost' || url.hostname === '[::1]');
    } catch (_error) {
        return false;
    }
}

// Updates are controlled by Electron's main process. The web page receives
// status only; it cannot choose a feed URL, execute an installer or pass a path.
async function latestStableRelease(url) {
    if (url !== API_URL) throw new Error('Invalid release API URL');
    const response = await fetch(url, {
        headers: {
            Accept: 'application/vnd.github+json',
            'User-Agent': 'Automatic-Kimai-Desktop',
        },
        signal: AbortSignal.timeout(12000),
    });
    if (!response.ok) throw new Error('Release API unavailable');
    return response.json();
}

function macUpdaterTrusted() {
    if (process.platform !== 'darwin' || !app.isPackaged) return false;
    try {
        // An ad-hoc signature is insufficient: native macOS self-updating
        // requires a valid Developer ID signature and Gatekeeper approval.
        const bundle = path.resolve(app.getAppPath(), '../../..');
        execFileSync('/usr/bin/codesign', ['--verify', '--strict', '--deep', bundle], {
            stdio: 'ignore', timeout: 15000,
        });
        const signed = spawnSync('/usr/bin/codesign', ['--display', '--verbose=4', bundle], {
            encoding: 'utf8', timeout: 15000,
        });
        // codesign prints certificate details to stderr, not stdout.
        const details = (signed.stdout || '') + (signed.stderr || '');
        if (signed.status !== 0 ||
            !/Authority=Developer ID Application:/.test(details) ||
            !/TeamIdentifier=[A-Z0-9]+/.test(details)) return false;
        execFileSync('/usr/sbin/spctl', ['--assess', '--type', 'execute', bundle], {
            stdio: 'ignore', timeout: 15000,
        });
        return true;
    } catch {
        return false;
    }
}

let splashWindow;
let updateTimer;

app.whenReady().then(() => {
    try {
        splashWindow = createSplash(appPath, import.meta.dirname);
    } catch (error) {
        console.error('Error creating splash screen:', error);
    }

    // Updates are auxiliary: an unavailable GitHub feed must never prevent
    // NativePHP's local server, scheduler or data collection from starting.
    NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);
    try {
        const manager = new DesktopUpdateManager({
            app,
            updater: electronUpdater.autoUpdater,
            fetchRelease: latestStableRelease,
            openExternal: () => shell.openExternal(RELEASES_URL),
            broadcast: status => {
                for (const window of BrowserWindow.getAllWindows()) {
                    if (!window.isDestroyed()) window.webContents.send(UPDATE_CHANNEL, status);
                }
            },
            platform: process.platform,
            arch: process.arch,
            appPort: () => NativePHP.getAppPort(),
            canUseMacUpdates: macUpdaterTrusted(),
            appImage: Boolean(process.env.APPIMAGE),
        });
        manager.registerIpc(ipcMain);
        // Never download an update in the background; this only checks.
        if (app.isPackaged) {
            const initial = setTimeout(() => void manager.check(), 10000);
            initial.unref?.();
            updateTimer = setInterval(() => void manager.check(), 6 * 60 * 60 * 1000);
            updateTimer.unref?.();
            app.on('before-quit', () => clearInterval(updateTimer));
        }
    } catch {
        console.warn('Desktop updates are unavailable; the application will keep running.');
    }
});

app.on('browser-window-created', (event, window) => {
    if (window !== splashWindow) {
        window.webContents.setWindowOpenHandler(({ url }) => {
            openInDefaultBrowser(url);
            return { action: 'deny' };
        });
        window.webContents.on('will-navigate', (evt, url) => {
            if (isAppNavigation(url)) return;
            evt.preventDefault();
            openInDefaultBrowser(url);
        });
    }
    if (splashWindow && window !== splashWindow) {
        window.webContents.on('did-navigate', (evt, url) => {
            if (url.startsWith('http://127.0.0.1') || url.startsWith('http://localhost')) {
                if (splashWindow) {
                    splashWindow.close();
                    splashWindow = null;
                }
            }
        });
    }
});
