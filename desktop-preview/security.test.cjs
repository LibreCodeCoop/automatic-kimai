'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { isAllowedNavigation } = require('./security.cjs');

test('permits the PHP dashboard and Telegram onboarding on loopback', () => {
  assert.equal(isAllowedNavigation('http://127.0.0.1:6817/'), true);
  assert.equal(isAllowedNavigation('http://127.0.0.1:6817/#telegram-setup'), true);
  assert.equal(isAllowedNavigation('http://127.0.0.1:6817/telegram/status'), true);
});

test('blocks external navigation, other local ports and URL credentials', () => {
  for (const url of [
    'https://example.org/',
    'https://127.0.0.1:6817/',
    'http://127.0.0.1:6818/',
    'http://localhost:6817/',
    'http://user:pass@127.0.0.1:6817/',
    'file:///etc/passwd',
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    '//example.org/',
    '',
    'not-a-url'
  ]) {
    assert.equal(isAllowedNavigation(url), false, url);
  }
});

test('only the exact official release destination is eligible for external opening', () => {
  const { isOfficialReleaseUrl } = require('./security.cjs');
  assert.equal(isOfficialReleaseUrl('https://github.com/LibreCodeCoop/automatic-kimai/releases/latest'), true);
  for (const url of [
    'https://github.com/LibreCodeCoop/automatic-kimai/releases/latest/',
    'https://github.com/LibreCodeCoop/automatic-kimai/releases/latest?redirect=evil',
    'https://github.com.evil.example/LibreCodeCoop/automatic-kimai/releases/latest',
    'http://github.com/LibreCodeCoop/automatic-kimai/releases/latest',
    'https://github.com/other/repository/releases/latest',
    'javascript:alert(1)',
  ]) assert.equal(isOfficialReleaseUrl(url), false, url);
});
