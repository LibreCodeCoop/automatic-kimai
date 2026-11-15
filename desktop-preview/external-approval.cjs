'use strict';

const { LOCAL_ORIGIN } = require('./security.cjs');

/**
 * The renderer is restricted to local PHP. Only the active Nextcloud
 * LoginFlow v2 URL may be opened by the host OS, in its default browser.
 * No URL is fetched by Electron's renderer or loaded into a popup.
 */
function isSafeNextcloudApprovalUrl(value) {
  if (typeof value !== 'string' || value.length > 1200 ||
      /[\x00-\x20\x7f\\]/.test(value)) return false;

  try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password ||
        url.search || url.hash) return false;
    const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '');
    if (host === 'localhost' || host.endsWith('.localhost') ||
        host === '::1' || host === '0.0.0.0' ||
        host.startsWith('127.') || host.startsWith('169.254.')) return false;

    return /\/(?:index\.php\/)?login\/v2\/flow\/[A-Za-z0-9_-]{16,300}$/.test(url.pathname);
  } catch {
    return false;
  }
}

/** Read the currently active authorization from the same loopback-only PHP API. */
async function currentNextcloudStatus() {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 3500);
  try {
    const response = await fetch(LOCAL_ORIGIN + '/nextcloud/status', {
      method: 'GET',
      cache: 'no-store',
      redirect: 'error',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });
    if (!response.ok) return null;
    const length = Number(response.headers.get('content-length') || 0);
    if (length > 8192) return null;
    const body = await response.text();
    if (body.length > 8192) return null;
    return JSON.parse(body);
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

async function isCurrentNextcloudApproval(url, getStatus = currentNextcloudStatus) {
  if (!isSafeNextcloudApprovalUrl(url)) return false;
  try {
    const state = await getStatus();
    return state !== null && typeof state === 'object' &&
      state.waiting === true && state.login_url === url;
  } catch {
    return false;
  }
}

/**
 * Electron window-open handlers must return synchronously; the OS browser
 * launch happens asynchronously only after a fresh server-side comparison.
 * All renderer-created windows remain forbidden. No tokens are logged.
 */
function createExternalApprovalHandler({ isApproved, openExternal }) {
  let opening = false;
  return ({ url } = {}) => {
    if (isSafeNextcloudApprovalUrl(url) && !opening) {
      opening = true;
      Promise.resolve()
        .then(() => isApproved(url))
        .then(approved => approved ? openExternal(url) : undefined)
        .catch(() => { /* Fail closed; do not expose LoginFlow URL in logs. */ })
        .finally(() => { opening = false; });
    }
    return { action: 'deny' };
  };
}

/**
 * Browser launches caused by a successful user-initiated local POST, not by
 * background status polling or untrusted renderer-provided URLs.
 */
function createPendingApprovalOpener({ getStatus = currentNextcloudStatus, openExternal }) {
  let opening = false;
  return async () => {
    if (opening) return false;
    opening = true;
    try {
      const state = await getStatus();
      if (!state || state.waiting !== true ||
          !await isCurrentNextcloudApproval(state.login_url, async () => state)) {
        return false;
      }
      await openExternal(state.login_url);
      return true;
    } catch {
      return false;
    } finally {
      opening = false;
    }
  };
}

module.exports = {
  isSafeNextcloudApprovalUrl,
  isCurrentNextcloudApproval,
  createExternalApprovalHandler,
  createPendingApprovalOpener,
};
