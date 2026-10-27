#!/usr/bin/env bash
# Build the installable plugin archive: dist/MomoBirdAI-WordPress-<version>.zip
# with a single top-level momobird-ai/ directory (WordPress installs it as the
# plugin folder).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "$root/momobird-ai.php" | head -1)"
[ -n "$version" ] || { echo "cannot read Version from momobird-ai.php" >&2; exit 1; }

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/momobird-ai" "$root/dist"
for item in momobird-ai.php uninstall.php README.md includes assets; do
  cp -R "$root/$item" "$stage/momobird-ai/"
done

out="$root/dist/MomoBirdAI-WordPress-$version.zip"
rm -f "$out"
(cd "$stage" && zip -qr -X "$out" momobird-ai -x '*.DS_Store')
echo "$out"
