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

module.exports = { LOCAL_ORIGIN, isAllowedNavigation };
