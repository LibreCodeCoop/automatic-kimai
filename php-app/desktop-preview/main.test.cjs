'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const real = require('./external-approval.cjs');

test('Electron main wires approved LoginFlow links to the OS browser, keeping all popups denied', async () => {
  const approved = 'https://cloud.example.org/login/v2/flow/' + 'A'.repeat(40);
  const opened = [];
  const registered = {};
  const fakeWindow = {
    webContents: {
      setWindowOpenHandler: handler => { registered.open = handler; },
      on: (name, handler) => { registered[name] = handler; },
    },
    once: () => {},
    on: () => {},
    loadURL: url => { registered.url = url; },
  };
  class FakeBrowserWindow { constructor() { return fakeWindow; } }

  const electron = {
    app: {
      requestSingleInstanceLock: () => true,
      setName: () => {},
      setAppUserModelId: () => {},
      on: () => {},
      whenReady: () => Promise.resolve(),
    },
    BrowserWindow: FakeBrowserWindow,
    Menu: { setApplicationMenu: () => {} },
    session: {
      defaultSession: {
        setPermissionRequestHandler: () => {},
        setPermissionCheckHandler: () => {},
        on: () => {},
        webRequest: {
          onBeforeRequest: (_filter, callback) => { registered.networkFilter = callback; },
          onCompleted: (_filter, callback) => { registered.completed = callback; },
        },
      },
    },
    shell: {
      openExternal: async url => { opened.push(url); },
    },
  };

  const source = fs.readFileSync(path.join(__dirname, 'main.cjs'), 'utf8');
  const sandbox = {
    __dirname,
    require: name => {
      if (name === 'electron') return electron;
      if (name === './external-approval.cjs') {
        return {
          ...real,
          isCurrentNextcloudApproval: async url => url === approved,
          createPendingApprovalOpener: ({ openExternal }) => real.createPendingApprovalOpener({
            getStatus: async () => ({ waiting: true, login_url: approved }),
            openExternal,
          }),
        };
      }
      if (name === './security.cjs') return require('./security.cjs');
      return require(name);
    },
  };
  vm.runInNewContext(source, sandbox, { filename: 'main.cjs' });
  await new Promise(resolve => setImmediate(resolve));

  assert.equal(typeof registered.open, 'function');
  assert.equal(typeof registered.completed, 'function');
  // Only a successful, deliberate login start causes automatic browser opening.
  registered.completed({ method: 'GET', statusCode: 200 });
  registered.completed({ method: 'POST', statusCode: 422 });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, []);
  registered.completed({ method: 'POST', statusCode: 200 });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, [approved]);
  opened.length = 0;
  assert.equal(registered.url, 'http://127.0.0.1:6817/');
  assert.equal(typeof registered.networkFilter, 'function');
  assert.equal(typeof registered['will-navigate'], 'function');
  assert.deepEqual({ ...registered.open({ url: approved }) }, { action: 'deny' });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, [approved]);

  const other = 'https://evil.example/login/v2/flow/' + 'B'.repeat(40);
  assert.deepEqual({ ...registered.open({ url: other }) }, { action: 'deny' });
  registered['will-navigate']({
    preventDefault: () => { registered.prevented = true; },
  }, other);
  assert.equal(registered.prevented, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, [approved], 'unapproved destinations must not reach shell.openExternal');

  let result;
  registered.networkFilter({ url: 'https://evil.example/' }, value => { result = value; });
  assert.equal(result.cancel, true);
  registered.networkFilter({ url: 'http://127.0.0.1:6817/nextcloud/status' }, value => { result = value; });
  assert.equal(result.cancel, false);
});
