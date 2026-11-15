'use strict';

const { app, BrowserWindow, Menu, session, shell } = require('electron');
const path = require('node:path');
const { LOCAL_ORIGIN, isAllowedNavigation } = require('./security.cjs');
const { isCurrentNextcloudApproval, createExternalApprovalHandler, createPendingApprovalOpener } = require('./external-approval.cjs');

const ENTRY_URL = LOCAL_ORIGIN + '/';
const APP_ID = 'org.automatic-kimai.desktop';
const openPendingNextcloudApproval = createPendingApprovalOpener({
  openExternal: url => shell.openExternal(url),
});
let mainWindow;

if (!app.requestSingleInstanceLock()) {
  app.quit();
} else {
  app.setName('Automatic Kimai');
  app.setAppUserModelId(APP_ID);
  app.on('second-instance', () => {
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.focus();
    }
  });

  app.whenReady().then(() => {
    // The desktop preview opens only the authenticated local Laravel UI.
    // No Node APIs, Electron bridge or privileged preload are exposed.
    const webSession = session.defaultSession;
    webSession.setPermissionRequestHandler((_contents, _permission, callback) => {
      callback(false);
    });
    webSession.setPermissionCheckHandler(() => false);
    webSession.on('will-download', (event) => event.preventDefault());
    // Renderer network access stays on loopback. External Kimai/Telegram
    // APIs remain server-side PHP requests, never Electron-renderer requests.
    webSession.webRequest.onBeforeRequest(
      { urls: ['http://*/*', 'https://*/*'] },
      (details, callback) => callback({ cancel: !isAllowedNavigation(details.url) })
    );

    // Start is a user-initiated POST. On a successful response, check the
    // current pending authorization before opening the default OS browser.
    // Never launch an arbitrary URL supplied by the renderer.
    webSession.webRequest.onCompleted(
      { urls: [LOCAL_ORIGIN + '/nextcloud/start'] },
      details => {
        if (details.method === 'POST' && details.statusCode === 200) {
          void openPendingNextcloudApproval();
        }
      }
    );

    Menu.setApplicationMenu(null);
    mainWindow = new BrowserWindow({
      title: 'Automatic Kimai',
      width: 1200,
      height: 810,
      minWidth: 790,
      minHeight: 590,
      show: false,
      backgroundColor: '#f3f5f8',
      icon: path.join(__dirname, 'automatic-kimai.png'),
      autoHideMenuBar: true,
      webPreferences: {
        nodeIntegration: false,
        contextIsolation: true,
        sandbox: true,
        webSecurity: true,
        webviewTag: false,
        allowRunningInsecureContent: false,
        spellcheck: true,
      },
    });

    // New renderer windows stay blocked. An active Nextcloud LoginFlow v2
    // link is the sole exception to external browser launching, not to
    // navigation inside Electron. Re-check the exact URL against the current
    // PHP pending state before asking the OS to open it.
    mainWindow.webContents.setWindowOpenHandler(createExternalApprovalHandler({
      isApproved: isCurrentNextcloudApproval,
      openExternal: url => shell.openExternal(url),
    }));
    mainWindow.webContents.on('will-navigate', (event, url) => {
      if (!isAllowedNavigation(url)) event.preventDefault();
    });
    mainWindow.webContents.on('will-redirect', (event, url) => {
      if (!isAllowedNavigation(url)) event.preventDefault();
    });

    // Load only the local PHP service. The Electron binary does not bundle
    // credentials, app keys, Telegram sessions or private review data.
    mainWindow.loadURL(ENTRY_URL);
    mainWindow.once('ready-to-show', () => mainWindow.show());
    mainWindow.on('closed', () => { mainWindow = null; });
  });

  app.on('window-all-closed', () => app.quit());
}
