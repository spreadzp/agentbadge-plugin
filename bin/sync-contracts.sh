#!/usr/bin/env bash
# SLICE-262-33 — sync the versioned contract verbatim from the
# authoritative source (server repo spec/) into contracts/, then
# regenerate MANIFEST.md checksums. PHP consumes contracts/ without
# Node (D-262-Q12) — this script is the ONLY write path into it.
#
# Usage: bin/sync-contracts.sh [spec-dir]
#   spec-dir defaults to $AGENTBADGE_SPEC_DIR or the monorepo path.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SPEC="${1:-${AGENTBADGE_SPEC_DIR:-/mnt/new-storage/projects/my/agents-ai/hedera/hackathon/server/docs/EPICS/262-agent-surface-intelligence/spec}}"
DST="$ROOT/contracts"

[ -d "$SPEC" ] || { echo "spec dir not found: $SPEC" >&2; exit 2; }

# verbatim copy — remove contract files absent upstream (empty dirs
# and stale fixtures must not drift), keep nothing plugin-local here
rsync -a --delete \
  --exclude 'MANIFEST.md' \
  "$SPEC/" "$DST/"

# prune empty dirs rsync leaves behind
find "$DST" -type d -empty -delete

# regenerate checksums (MANIFEST.md excluded from itself)
cd "$ROOT"
{
  echo "# contracts/ — AgentBadge Intel protocol contract"
  echo ""
  echo "Single authoritative source: \`hackathon/server/docs/EPICS/"
  echo "262-agent-surface-intelligence/spec/\` in the AgentBadge server repo."
  echo "This directory is a **verbatim copy** — PHP consumes the versioned"
  echo "contract without Node (D-262-Q12). Do not edit here; regenerate via"
  echo "\`bin/sync-contracts.sh\`. Verify: \`bin/check-contracts.sh\`."
  echo ""
  echo "Synced: $(date -u +%Y-%m-%dT%H:%M:%SZ) from $SPEC"
  echo ""
  echo "## Checksums (sha256)"
  echo ""
  find contracts -type f ! -name MANIFEST.md -print0 \
    | sort -z | xargs -0 sha256sum
} > contracts/MANIFEST.md

echo "synced $SPEC → contracts/ ($(find "$DST" -type f | wc -l) files)"
