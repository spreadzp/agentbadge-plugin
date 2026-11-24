#!/usr/bin/env bash
# SLICE-262-24 — reproducible private-beta zip channel.
# Stages the .distignore ship-list into agentbadge/ and zips it —
# the same tree wp.org will ship later; only channel/config differ
# (AGENTBADGE_API_BASE define in wp-config.php, not plugin code).
# Usage: bash bin/build-zip.sh [out=dist/agentbadge.zip]
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-dist/agentbadge.zip}"

php bin/build-release.php >/dev/null          # dist/release-manifest.json
STAGE="$(mktemp -d)/agentbadge"
mkdir -p "$STAGE"

# Ship-set = dirs from .distignore comments + top-level runtime files.
for d in includes templates assets contracts; do
	[ -d "$d" ] && cp -r "$d" "$STAGE/"
done
for f in *.php *.md readme.txt LICENSE*; do
	[ -f "$f" ] && cp "$f" "$STAGE/"
done
cp dist/release-manifest.json "$STAGE/"
find "$STAGE" -name 'MANIFEST.md' -delete     # dev checksum doc, not shipped

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
( cd "$(dirname "$STAGE")" && zip -qr "$OLDPWD/$OUT" agentbadge )
sha256sum "$OUT" | tee "$OUT.sha256"
unzip -l "$OUT" | tail -1
