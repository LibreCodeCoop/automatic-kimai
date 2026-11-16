import remote from '@electron/remote';
import { contextBridge, ipcRenderer, webUtils } from 'electron';

// -------------------------------------------------------------------
// The Native helper
// -------------------------------------------------------------------
const Native = {
    on: (event, callback) => {
        ipcRenderer.on('native-event', (_, data) => {
            // Strip leading slashes
            event = event.replace(/^(\\)+/, '');
            data.event = data.event.replace(/^(\\)+/, '');

            if (event === data.event) {
                return callback(data.payload, event);
            }
        });
    },
    contextMenu: (template) => {
        const menu = remote.Menu.buildFromTemplate(template);
        menu.popup({ window: remote.getCurrentWindow() });
    },
    // The file system path of a File from a drop or an <input type="file">.
    // Electron 32 removed File.path and webUtils is only available in a preload.
    // Returns an empty string when the File isn't backed by a file on disk.
    getPathForFile: (file: File): string => webUtils.getPathForFile(file),
};

contextBridge.exposeInMainWorld('Native', Native);

// Typed, fixed-purpose update commands. Never expose the generic IPC channel
// or accept an installer URL from the renderer (even the local web page).
const updaterCommands = {
    status: 'automatic-kimai:update-status-get',
    check: 'automatic-kimai:update-check',
    download: 'automatic-kimai:update-download',
    restart: 'automatic-kimai:update-restart',
    openReleases: 'automatic-kimai:update-open-releases',
};
contextBridge.exposeInMainWorld('AutomaticKimaiUpdates', {
    status: () => ipcRenderer.invoke(updaterCommands.status),
    check: () => ipcRenderer.invoke(updaterCommands.check),
    download: () => ipcRenderer.invoke(updaterCommands.download),
    restart: () => ipcRenderer.invoke(updaterCommands.restart),
    openReleases: () => ipcRenderer.invoke(updaterCommands.openReleases),
    onStatus: (callback: (status: unknown) => void) => {
        if (typeof callback !== 'function') return () => {};
        const listener = (_event: Electron.IpcRendererEvent, status: unknown) => callback(status);
        ipcRenderer.on('automatic-kimai:update-status', listener);
        return () => ipcRenderer.removeListener('automatic-kimai:update-status', listener);
    },
});


// -------------------------------------------------------------------
// Log events
// -------------------------------------------------------------------
ipcRenderer.on('log', (event, { level, message, context }) => {
    if (level === 'error') {
        console.error(`[${level}] ${message}`, context);
    } else if (level === 'warn') {
        console.warn(`[${level}] ${message}`, context);
    } else {
        console.log(`[${level}] ${message}`, context);
    }
});

// -------------------------------------------------------------------
// Livewire event listeners
// -------------------------------------------------------------------
ipcRenderer.on('native-event', (event, data) => {
    // Strip leading slashes
    data.event = data.event.replace(/^(\\)+/, '');

    // Forward event to renderer context
    // Handler injected via Events\LivewireDispatcher
    window.postMessage(
        {
            type: 'native-event',
            event: data.event,
            payload: data.payload,
        },
        '*',
    );
});

// -------------------------------------------------------------------
// Let the client know preload is fully evaluated
// -------------------------------------------------------------------
contextBridge.exposeInMainWorld(
    'native:initialized',
    (function () {
        // This is admittedly a bit hacky. Due to context isolation
        // we don't have direct access to the renderer window object,
        // but by assigning a bridge function that executes itself inside
        // the renderer context we can hack around it.

        // It's recommended to use window.postMessage & dispatch an
        // event from the renderer itself, but we're loading webcontent
        // from localhost. We don't have a renderer process we can access.
        // Though this is hacky it works well and is the simplest way to do this
        // without sprinkling additional logic all over the place.

        window.dispatchEvent(new CustomEvent('native:init'));

        return true;
    })(),
);
