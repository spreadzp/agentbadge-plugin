#!/usr/bin/env bash
# SLICE-262-33 — protocol-compat gate: contracts/ MUST equal the
# server spec verbatim AND match MANIFEST.md checksums. Drift =
# plugin and backend disagree on the wire contract — fail loudly.
#
# Usage: bin/check-contracts.sh [spec-dir]   (exit 1 on drift)
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SPEC="${1:-${AGENTBADGE_SPEC_DIR:-/mnt/new-storage/projects/my/agents-ai/hedera/hackathon/server/docs/EPICS/262-agent-surface-intelligence/spec}}"
DST="$ROOT/contracts"
fail=0

if [ -d "$SPEC" ]; then
  # byte-for-byte parity (MANIFEST.md is generated, not synced)
  if ! diff -r "$SPEC" "$DST" -x MANIFEST.md >/dev/null 2>&1; then
    echo "✗ DRIFT: contracts/ ≠ $SPEC"
    diff -r "$SPEC" "$DST" -x MANIFEST.md | head -20
    fail=1
  else
    echo "✓ contracts/ ≡ spec (verbatim)"
  fi
else
  echo "! spec dir absent ($SPEC) — checking checksums only"
fi

# checksum integrity — every listed file matches its recorded hash
cd "$ROOT"
if [ -f contracts/MANIFEST.md ]; then
  bad=$(awk '/^[0-9a-f]{64}  /{print $1"  "$2}' contracts/MANIFEST.md \
    | while read -r sum file; do
        [ -f "$file" ] || { echo "MISSING $file"; continue; }
        actual=$(sha256sum "$file" | cut -d' ' -f1)
        [ "$actual" = "$sum" ] || echo "CHANGED $file"
      done)
  if [ -n "$bad" ]; then
    echo "✗ checksum mismatch:"; echo "$bad"; fail=1
  else
    echo "✓ MANIFEST checksums valid"
  fi
fi

exit $fail
