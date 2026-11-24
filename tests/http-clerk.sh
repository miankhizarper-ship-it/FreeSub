#!/usr/bin/env bash
#
# End-to-end Clerk flow test.
#
# Starts the app with a Clerk test double (committed fixture RSA key + in-memory
# Backend API) and drives the real endpoints with real HTTP requests:
#
#   login page -> handoff channel -> bridge -> CSRF form -> session
#   -> dashboard -> admin area -> logout
#   plus webhook signatures, account migration and deletion.
#
# It does NOT prove anything about a live Clerk instance: no network call is
# made and no real Clerk secret is used. See README "Verifying against Clerk".
#
# Usage:  tests/http-clerk.sh [port]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${1:-8099}"
PHP_BIN="${PHP_BIN:-php}"

export APP_URL="http://localhost:${PORT}"
export DB_DRIVER="sqlite"
export SESSION_NAME="FREESUBE2E"
export SESSION_SECURE="false"
export CLERK_PUBLISHABLE_KEY="pk_test_ZXRlcm5hbC1wb3Jwb2lzZS0yMTEyLmNsZXJrLmFjY291bnRzLmRldiQ"
export CLERK_SECRET_KEY="sk_test_e2e_harness_only"
export CLERK_AUTHORIZED_PARTIES="http://localhost:${PORT},http://127.0.0.1:${PORT}"
export CLERK_FRONTEND_API="freesub-test.clerk.accounts.dev"
export CLERK_JWKS_URL="https://freesub-test.clerk.accounts.dev/.well-known/jwks.json"
export CLERK_BACKEND_API="https://api.clerk.test"
export CLERK_WEBHOOK_SECRET="whsec_ZjZkYzI4OGI5N2Y4NGQ3NThkZTVjM2FiMWE5ZDBmMzI="
export CLERK_SESSION_RECHECK="0"
export CLERK_BREAK_GLASS="false"
export CLERK_JWKS_CACHE_TTL="0"
export FREESUB_E2E_ORIGIN="http://localhost:${PORT}"

echo "Starting the app on port ${PORT} with the Clerk test double…"
"${PHP_BIN}" -S "127.0.0.1:${PORT}" -t "${ROOT}" "${ROOT}/tests/router-e2e.php" >/tmp/freesub-e2e-${PORT}.log 2>&1 &
SERVER_PID=$!
trap 'kill "${SERVER_PID}" 2>/dev/null || true' EXIT

for _ in $(seq 1 50); do
    if curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/login.php" 2>/dev/null; then
        break
    fi
    sleep 0.2
done

python3 "${ROOT}/tests/http_clerk_flow.py" "http://127.0.0.1:${PORT}"
