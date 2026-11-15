#!/bin/sh
# Local Electron preview: opens a desktop window, not the default browser.
# The PHP backend remains an independent loopback-only service in Docker.
set -eu

self=$(readlink -f "$0")
here=$(dirname "$self")
app=$(dirname "$here")
electron="$app/nativephp/electron/node_modules/electron/dist/electron"
main="$here/main.cjs"
url="http://127.0.0.1:6817/"

fail() {
    if [ "${1:-}" != "--quiet" ] && command -v notify-send >/dev/null 2>&1; then
        notify-send "Automatic Kimai" "O aplicativo local não está disponível. Confira a instalação PHP."
    fi
    echo "Automatic Kimai desktop: backend or Electron runtime unavailable" >&2
    exit 1
}

if [ "${1:-}" != "" ] && [ "$1" != "--check" ]; then
    echo "Usage: automatic-kimai-desktop [--check]" >&2
    exit 2
fi

if [ ! -x "$electron" ] || [ ! -r "$main" ]; then fail --quiet; fi

healthy() {
    curl --noproxy '*' --fail --silent --show-error --max-time 4 "$url" 2>/dev/null \
        | grep -q '<title>Automatic Kimai'
}

if ! healthy; then
    # Restart only the known local PHP preview container, if it was stopped.
    # Do not create/reconfigure containers or touch the existing Python pilot.
    if command -v docker >/dev/null 2>&1 &&
       docker container inspect automatic-kimai-php-preview >/dev/null 2>&1; then
        docker start automatic-kimai-php-preview >/dev/null 2>&1 || true
    fi
    i=0
    while [ "$i" -lt 12 ]; do
        if healthy; then break; fi
        i=$((i+1))
        sleep 1
    done
fi

if ! healthy; then fail ""; fi

if [ "${1:-}" = "--check" ]; then
    printf 'ELECTRON_DESKTOP_PREVIEW_READY=true\nPHP_BACKEND_LOOPBACK=true\n'
    exit 0
fi

# Never disable Chromium/Electron sandbox or expose the PHP interface on LAN.
unset ELECTRON_RUN_AS_NODE NODE_OPTIONS
exec "$electron" "$main"
