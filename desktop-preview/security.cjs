'use strict';

const LOCAL_ORIGIN = 'http://127.0.0.1:6817';

/** The Electron renderer must never navigate away from the local PHP app. */
function isAllowedNavigation(value) {
  try {
    const parsed = new URL(value);
    return parsed.origin === LOCAL_ORIGIN
      && parsed.username === ''
      && parsed.password === '';
  } catch {
    return false;
  }
}

// Only this immutable public release destination may open in the OS browser.
// No user-provided URL, query string or alternate host is accepted.
const OFFICIAL_RELEASES_URL = 'https://github.com/LibreCodeCoop/automatic-kimai/releases/latest';
function isOfficialReleaseUrl(value) {
  return value === OFFICIAL_RELEASES_URL;
}

module.exports = { LOCAL_ORIGIN, OFFICIAL_RELEASES_URL, isAllowedNavigation, isOfficialReleaseUrl };
