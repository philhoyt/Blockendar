#!/usr/bin/env bash
#
# Boot a local WordPress Playground with Blockendar and the demo content.
#
#   npm run playground
#
# Runs on php-wasm in Node — no Docker, so this works when wp-env does not.
# It builds both plugin zips from the working tree, unpacks them to a temp
# directory and mounts them, so what you see is the code you have right now.
#
# The published demo uses _playground/blueprint.json, which installs the
# released zips from GitHub. This uses _playground/local-blueprint.json.

set -euo pipefail

PORT="${PORT:-9400}"
CLI_VERSION="${PLAYGROUND_CLI_VERSION:-3.1.55}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/blockendar-playground.XXXXXX")"

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

cd "$ROOT"

echo "→ Building assets and plugin zips…"
npm run build --silent
npm run plugin-zip --silent > /dev/null
npm run plugin-zip:demo --silent > /dev/null

echo "→ Staging plugins in $STAGE"
unzip -q "$ROOT/blockendar.zip" -d "$STAGE"
unzip -q "$ROOT/demo-plugin/blockendar-demo.zip" -d "$STAGE"

echo "→ Starting Playground on http://127.0.0.1:${PORT}"
echo "  (first run downloads WordPress and php-wasm; Ctrl-C to stop)"

npx --yes "@wp-playground/cli@${CLI_VERSION}" server \
	--port "$PORT" \
	--php 8.3 \
	--login \
	--mount "$STAGE/blockendar:/wordpress/wp-content/plugins/blockendar" \
	--mount "$STAGE/blockendar-demo:/wordpress/wp-content/plugins/blockendar-demo" \
	--blueprint "$ROOT/_playground/local-blueprint.json"
