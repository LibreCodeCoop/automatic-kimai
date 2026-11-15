#!/bin/sh
# Install an ordinary KDE/Linux menu entry and desktop shortcut for this user.
# No application window is opened by this installer.
set -eu

self=$(readlink -f "$0")
base=$(dirname "$self")
source_launcher="$base/launch.sh"
source_icon="$base/automatic-kimai.png"
app_id="org.automatic-kimai"

bin_dir="$HOME/.local/bin"
menu_dir="$HOME/.local/share/applications"
icon_dir="$HOME/.local/share/icons/hicolor/256x256/apps"
launcher="$bin_dir/automatic-kimai-desktop"
menu="$menu_dir/$app_id.desktop"
icon="$icon_dir/$app_id.png"

if [ ! -r "$source_launcher" ] || [ ! -r "$source_icon" ]; then
    echo "Missing Automatic Kimai preview files" >&2
    exit 1
fi

mkdir -p "$bin_dir" "$menu_dir" "$icon_dir"
if [ -e "$launcher" ] || [ -L "$launcher" ]; then
    if [ "$(readlink -f "$launcher")" != "$(readlink -f "$source_launcher")" ]; then
        echo "Different executable already uses $launcher; not overwriting" >&2
        exit 1
    fi
else
    ln -s "$source_launcher" "$launcher"
fi

install -m 644 "$source_icon" "$icon"
if [ -e "$menu" ]; then
    if ! grep -Fq "Exec=$launcher" "$menu"; then
        echo "Different application already uses $menu; not overwriting" >&2
        exit 1
    fi
fi
cat > "$menu" <<EOF
[Desktop Entry]
Version=1.0
Type=Application
Name=Automatic Kimai
GenericName=Revisão de horas
Comment=Automatic Kimai em janela Electron com backend PHP local
Exec=$launcher
TryExec=$launcher
Icon=$icon
Terminal=false
Categories=Office;
Keywords=Kimai;Horas;Trabalho;Telegram;
StartupNotify=true
SingleMainWindow=true
EOF
chmod 644 "$menu"
if command -v desktop-file-validate >/dev/null 2>&1; then
    desktop-file-validate "$menu"
fi
if [ -d "$HOME/Desktop" ]; then
    desktop_link="$HOME/Desktop/Automatic-Kimai.desktop"
    if [ ! -e "$desktop_link" ] ||
       grep -Fq "Exec=$launcher" "$desktop_link"; then
        cp "$menu" "$desktop_link"
        chmod 755 "$desktop_link"
    fi
fi
if command -v kbuildsycoca6 >/dev/null 2>&1; then
    kbuildsycoca6 --noincremental >/dev/null 2>&1 || true
fi

echo "DESKTOP_MENU_ENTRY=$menu"
echo "DESKTOP_ICON=$icon"
echo "CLICK_TO_LAUNCH=true"
