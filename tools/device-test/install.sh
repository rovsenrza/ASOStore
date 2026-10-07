#!/bin/sh
# Installs or updates the device-test agent for the current user and starts it under launchd.
# The agent is copied out of the repository because launchd jobs may not read ~/Desktop.
#   tools/device-test/install.sh              install / update and start (keeps config, state, results)
#   tools/device-test/install.sh --uninstall  stop it and remove the launchd job (data stays)
set -eu
SOURCE=$(cd "$(dirname "$0")" && pwd)
TARGET="$HOME/Library/Application Support/RuAppStoreDeviceTest"
LABEL=com.ruappstore.device-test
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
DOMAIN="gui/$(id -u)"

launchctl bootout "$DOMAIN/$LABEL" 2>/dev/null || true
if [ "${1:-}" = "--uninstall" ]; then
    rm -f "$PLIST"
    echo "stopped; data left in $TARGET"
    exit 0
fi

mkdir -p "$TARGET/logs" "$HOME/Library/LaunchAgents"
cp "$SOURCE/agent.py" "$TARGET/agent.py"
swiftc -O "$SOURCE/screencheck.swift" -o "$TARGET/screencheck"
[ -f "$TARGET/config.json" ] || cp "$SOURCE/config.example.json" "$TARGET/config.json"

# caffeinate keeps the Mac out of idle sleep (on power) so the night window is not missed.
cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/bin/caffeinate</string><string>-i</string><string>-s</string>
        <string>/usr/bin/python3</string><string>-I</string><string>$TARGET/agent.py</string><string>run</string>
    </array>
    <key>WorkingDirectory</key><string>$TARGET</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key><string>/usr/bin:/bin:/usr/sbin:/sbin</string>
        <key>LC_ALL</key><string>en_US.UTF-8</string>
    </dict>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>ThrottleInterval</key><integer>60</integer>
    <key>StandardOutPath</key><string>$TARGET/logs/agent.log</string>
    <key>StandardErrorPath</key><string>$TARGET/logs/agent.log</string>
</dict>
</plist>
PLIST
plutil -lint "$PLIST" >/dev/null
launchctl bootstrap "$DOMAIN" "$PLIST"
echo "running; status: python3 \"$TARGET/agent.py\" status · log: $TARGET/logs/agent.log"
