#!/usr/bin/env bash
# EPIC-262 (SLICE-262-18): e2e runner — boots the fake intel
# backend, exports the harness constants, runs phpunit tests/e2e.
# Usage: bash tests/e2e/run-e2e.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

LOG=$(mktemp)
node tests/e2e/fake-backend.mjs --port 0 >"$LOG" 2>&1 &
FAKE_PID=$!
trap 'kill $FAKE_PID 2>/dev/null || true; rm -f "$LOG"' EXIT

# wait for the boot banner
for _ in $(seq 1 50); do
  if grep -q FAKE_PORT "$LOG" 2>/dev/null; then break; fi
  sleep 0.1
done
FAKE_PORT=$(grep FAKE_PORT "$LOG" | cut -d= -f2-)
FAKE_ROOT_PUB=$(grep FAKE_ROOT_PUB "$LOG" | cut -d= -f2-)
if [ -z "${FAKE_PORT:-}" ] || [ -z "${FAKE_ROOT_PUB:-}" ]; then
  echo "fake backend failed to boot:"; cat "$LOG"; exit 1
fi

export E2E_AGENTBADGE_API_BASE="http://127.0.0.1:${FAKE_PORT}"
export E2E_AGENTBADGE_ROOT_PUBKEY="$FAKE_ROOT_PUB"
export E2E_AGENTBADGE_SITE_KEY="absk_fakesite0000000000000000000001"
echo "fake backend @ ${E2E_AGENTBADGE_API_BASE} (root ${FAKE_ROOT_PUB:0:16}…)"
vendor/bin/phpunit -c tests/e2e/phpunit-e2e.xml "$@"
