import NativePHP from '#plugin';
import { app, BrowserWindow, shell } from 'electron';
import path from 'path';
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

let splashWindow;

app.whenReady().then(() => {
    try {
        splashWindow = createSplash(appPath, import.meta.dirname);
    } catch (error) {
        console.error('Error creating splash screen:', error);
    }

    NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);
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
