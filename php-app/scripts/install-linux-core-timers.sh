#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
[[ "$(uname -s)" == Linux ]] || { echo 'Linux systemd required' >&2; exit 2; }
app="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
sync_apply=
enable_telegram=false
for option in "$@"; do
  case "$option" in
    --apply) sync_apply=' --apply' ;;
    --enable-telegram) enable_telegram=true ;;
    *) echo "Unknown option: $option" >&2; exit 2 ;;
  esac
done
runtime="${XDG_DATA_HOME:-$HOME/.local/share}/automatic-kimai-php-runtime/php"
php="${AUTOMATIC_KIMAI_PHP_BINARY:-$runtime}"
units="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
if [[ ! -x "$php" ]]; then
  echo 'Run the NativePHP audio installer or provide AUTOMATIC_KIMAI_PHP_BINARY.' >&2
  exit 2
fi
[[ "$app" != *$'\n'* && "$app" != *'%'* ]] ||
  { echo 'Unsupported directory for systemd user unit' >&2; exit 2; }
[[ "$php" != *$'\n'* && "$php" != *'"'* && "$php" != *'%'* ]] ||
  { echo 'Unsupported PHP executable name' >&2; exit 2; }
(cd "$app" && "$php" artisan list --raw >/dev/null)
install -d -m 700 "$units"

cat > "$units/automatic-kimai-php-shadow.service" <<EOF
[Unit]
Description=Automatic Kimai PHP: read-only daily reconciliation
After=network-online.target

[Service]
Type=oneshot
WorkingDirectory=$app
ExecStart="$php" artisan automatic-kimai:sync$sync_apply
TimeoutStartSec=150s
NoNewPrivileges=true
Nice=10
UMask=0077
EOF
if [[ ! -f "$units/automatic-kimai-php-shadow.timer" ]]; then
  cat > "$units/automatic-kimai-php-shadow.timer" <<'EOF'
[Unit]
Description=Automatic Kimai PHP: read-only recurring sync

[Timer]
OnStartupSec=3min
OnUnitInactiveSec=30min
AccuracySec=1min
Unit=automatic-kimai-php-shadow.service

[Install]
WantedBy=timers.target
EOF
fi

# A GMP-enabled PHP is necessary for predictable MTProto latency.
# The developer Docker fallback never becomes an end-user dependency.
telegram_exec="\"$php\" artisan automatic-kimai:telegram-scan"
if ! "$php" -r 'exit(extension_loaded("gmp")?0:1);'; then
  image="${AUTOMATIC_KIMAI_TELEGRAM_DOCKER_IMAGE:-}"
  if [[ -z "$image" ]]; then
    if [[ "$enable_telegram" == true ]]; then
      echo 'NativePHP PHP lacks GMP. Supply a GMP-enabled PHP or an explicit developer Docker fallback.' >&2
      exit 2
    fi
  else
    if [[ ! "$image" =~ ^[A-Za-z0-9._:/@-]{1,180}$ ]]; then
      echo 'Invalid Docker image name' >&2; exit 2
    fi
    docker_bin="$(command -v docker)"
    if [[ ! -x "$docker_bin" ]] ||
       ! docker run --rm --network none "$image" php -r 'exit(extension_loaded("gmp")?0:1);'; then
      echo 'Developer Docker fallback must contain PHP with GMP.' >&2
      exit 2
    fi
    telegram_exec="\"$docker_bin\" run --rm --network host --user $(id -u):$(id -g) -v \"$app:/app\" -w /app \"$image\" php artisan automatic-kimai:telegram-scan"
  fi
fi
cat > "$units/automatic-kimai-php-telegram-scan.service" <<EOF
[Unit]
Description=Automatic Kimai PHP: read-only selected Telegram group scan
After=network-online.target

[Service]
Type=oneshot
WorkingDirectory=$app
ExecStart=$telegram_exec
TimeoutStartSec=180s
NoNewPrivileges=true
Nice=10
UMask=0077
EOF
cat > "$units/automatic-kimai-php-telegram-scan.timer" <<'EOF'
[Unit]
Description=Automatic Kimai PHP: scan explicitly selected Telegram groups

[Timer]
OnStartupSec=2min
OnUnitInactiveSec=5min
AccuracySec=30s
Unit=automatic-kimai-php-telegram-scan.service

[Install]
WantedBy=timers.target
EOF
chmod 600 "$units/automatic-kimai-php-shadow.service" "$units/automatic-kimai-php-telegram-scan.service" "$units/automatic-kimai-php-telegram-scan.timer"
systemctl --user daemon-reload
systemctl --user enable --now automatic-kimai-php-shadow.timer
# Avoid doubling MTProto requests with the still-active Python pilot.
# The dedicated PHP Telegram timer is installed but explicitly requires
# activation during a monitored staged cutover.
if [[ "$enable_telegram" == true ]]; then
  systemctl --user enable --now automatic-kimai-php-telegram-scan.timer
fi
echo PHP_NATIVE_SHADOW_TIMER_ENABLED=true
echo PHP_TELEGRAM_TIMER_INSTALLED=true
echo PYTHON_SERVICES_UNMODIFIED=true
