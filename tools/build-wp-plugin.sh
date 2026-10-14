#!/usr/bin/env sh
# Copy the desktop agent (client/) into the WordPress plugin and build an installable zip.
#   ./tools/build-wp-plugin.sh          -> dist/localia.zip  (upload via Plugins > Add New > Upload)
set -e
cd "$(dirname "$0")/.."
PLUGIN=wordpress-plugin/localia

rm -rf "$PLUGIN/agent"
mkdir -p "$PLUGIN/agent"
cp -R client/. "$PLUGIN/agent/"
rm -f "$PLUGIN/agent/.gitignore" "$PLUGIN/agent/js/config.js"

mkdir -p dist
rm -f dist/localia.zip
(cd wordpress-plugin && zip -qr ../dist/localia.zip localia -x '*.DS_Store')
echo "Built dist/localia.zip"
