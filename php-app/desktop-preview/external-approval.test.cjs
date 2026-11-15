'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  isSafeNextcloudApprovalUrl,
  isCurrentNextcloudApproval,
  createExternalApprovalHandler,
} = require('./external-approval.cjs');

const valid = 'https://cloud.example.org/index.php/login/v2/flow/' + 'A'.repeat(40);

test('accepts only HTTPS Nextcloud LoginFlow v2 links as possible browser destinations', () => {
  assert.equal(isSafeNextcloudApprovalUrl(valid), true);
  assert.equal(isSafeNextcloudApprovalUrl(
    'https://cloud.example.org/nextcloud/login/v2/flow/' + 'B'.repeat(40)
  ), true);
  for (const url of [
    'http://cloud.example.org/login/v2/flow/' + 'A'.repeat(40),
    'https://cloud.example.org',
    'https://cloud.example.org/ocs/v2.php/cloud/user',
    'https://cloud.example.org/login/v2/flow/' + 'A'.repeat(40) + '?return=evil',
    'https://cloud.example.org/login/v2/flow/' + 'A'.repeat(40) + '#x',
    'https://name:password@cloud.example.org/login/v2/flow/' + 'A'.repeat(40),
    'file:///tmp/test',
    'javascript:alert(1)',
    '//cloud.example.org/login/v2/flow/' + 'A'.repeat(40),
    'https://cloud.example.org/login/v2/flow/short',
    'https://127.0.0.1/login/v2/flow/' + 'A'.repeat(40),
  ]) {
    assert.equal(isSafeNextcloudApprovalUrl(url), false, url);
  }
});

test('only permits the pending URL from the local PHP status endpoint', async () => {
  let calls = 0;
  const fetchStatus = async () => {
    calls++;
    return { waiting: true, login_url: valid };
  };
  assert.equal(await isCurrentNextcloudApproval(valid, fetchStatus), true);
  assert.equal(calls, 1);
  assert.equal(await isCurrentNextcloudApproval(
    'https://evil.example/login/v2/flow/' + 'C'.repeat(40), fetchStatus
  ), false);
  assert.equal(calls, 2);
  assert.equal(await isCurrentNextcloudApproval(valid, async () => ({
    waiting: false, login_url: valid,
  })), false);
  assert.equal(await isCurrentNextcloudApproval(valid, async () => ({
    waiting: true, login_url: 'https://cloud.example.org/login/v2/flow/' + 'D'.repeat(40),
  })), false);
  assert.equal(await isCurrentNextcloudApproval('javascript:alert(1)', fetchStatus), false);
  assert.equal(calls, 2, 'invalid protocol must not even consult the backend');
});

test('always denies Electron popups; opens exactly the currently approved link outside sandbox', async () => {
  const opened = [];
  const handler = createExternalApprovalHandler({
    isApproved: async url => url === valid,
    openExternal: async url => { opened.push(url); },
  });
  assert.deepEqual(handler({ url: valid }), { action: 'deny' });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, [valid]);

  assert.deepEqual(handler({ url: 'https://evil.example/login/v2/flow/' + 'C'.repeat(40) }), { action: 'deny' });
  assert.deepEqual(handler({ url: 'javascript:alert(1)' }), { action: 'deny' });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, [valid]);
});

test('failed authorization and external opener exceptions never enable a popup', async () => {
  const opened = [];
  const deny = createExternalApprovalHandler({
    isApproved: async () => false,
    openExternal: async url => { opened.push(url); },
  });
  assert.deepEqual(deny({ url: valid }), { action: 'deny' });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, []);

  const failing = createExternalApprovalHandler({
    isApproved: async () => { throw new Error('backend offline'); },
    openExternal: async url => { opened.push(url); },
  });
  assert.deepEqual(failing({ url: valid }), { action: 'deny' });
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(opened, []);

  const failsOpen = createExternalApprovalHandler({
    isApproved: async () => true,
    openExternal: async () => { throw new Error('OS browser failed'); },
  });
  assert.deepEqual(failsOpen({ url: valid }), { action: 'deny' });
  await new Promise(resolve => setImmediate(resolve));
});

test('concurrent attempts do not open multiple browser tabs', async () => {
  let finish;
  let opened = 0;
  const handler = createExternalApprovalHandler({
    isApproved: async () => true,
    openExternal: async () => {
      opened++;
      await new Promise(resolve => { finish = resolve; });
    },
  });
  handler({ url: valid });
  handler({ url: valid });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(opened, 1);
  finish();
  await new Promise(resolve => setImmediate(resolve));
  handler({ url: valid });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(opened, 2);
  finish();
});
