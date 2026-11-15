#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

if [[ "$(uname -s)" != "Linux" ]]; then
  printf '%s\n' 'Audio PipeWire installer supports Linux only.' >&2
  exit 2
fi
app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
runtime_dir="${XDG_DATA_HOME:-$HOME/.local/share}/automatic-kimai-php-runtime"
php_binary="${AUTOMATIC_KIMAI_PHP_BINARY:-$runtime_dir/php}"
unit_dir="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
bundle="$app_dir/nativephp/binaries/main/linux/x64/php-8.4.zip"

# NativePHP bundles its PHP CLI; no global PHP installation or Composer is
# required on a user machine. Never package the user's profile and APP_KEY.
if [[ ! -x "$php_binary" ]]; then
  if [[ ! -f "$bundle" ]]; then
    printf '%s\n' 'NativePHP PHP runtime is not available.' >&2
    exit 2
  fi
  install -d -m 700 "$runtime_dir"
  temporary="$(mktemp "$runtime_dir/php.XXXXXXXX")"
  trap 'rm -f "$temporary"' EXIT
  unzip -p "$bundle" php > "$temporary"
  chmod 700 "$temporary"
  "$temporary" -v >/dev/null
  mv -f "$temporary" "$php_binary"
  trap - EXIT
fi
"$php_binary" -v >/dev/null
(cd "$app_dir" && "$php_binary" artisan automatic-kimai:audio-sample --status >/dev/null)
if [[ "$php_binary" == *$'\n'* || "$app_dir" == *$'\n'* ||
      "$php_binary" == *'%'* || "$app_dir" == *'%'* ||
      "$php_binary" == *'"'* || "$app_dir" == *'"'* ]]; then
  printf '%s\n' 'Unsafe PHP runtime or project path for systemd unit.' >&2
  exit 2
fi

install -d -m 700 "$unit_dir"
cat > "$unit_dir/automatic-kimai-php-audio.service" <<EOF
[Unit]
Description=Automatic Kimai PHP: read-only Telegram PipeWire metadata
After=pipewire.service graphical-session.target

[Service]
Type=oneshot
WorkingDirectory=$app_dir
ExecStart="$php_binary" artisan automatic-kimai:audio-sample
TimeoutStartSec=12s
NoNewPrivileges=true
Nice=10
UMask=0077
EOF
cat > "$unit_dir/automatic-kimai-php-audio.timer" <<'EOF'
[Unit]
Description=Automatic Kimai PHP: sample Telegram PipeWire metadata every 15 seconds

[Timer]
OnStartupSec=10s
OnUnitInactiveSec=15s
AccuracySec=1s
Unit=automatic-kimai-php-audio.service

[Install]
WantedBy=timers.target
EOF
chmod 600 "$unit_dir/automatic-kimai-php-audio.service" "$unit_dir/automatic-kimai-php-audio.timer"
systemctl --user daemon-reload
systemctl --user enable --now automatic-kimai-php-audio.timer
printf '%s\n' 'PHP_AUDIO_TIMER_ENABLED=true'
printf '%s\n' 'PYTHON_AUDIO_SERVICE_UNMODIFIED=true'
