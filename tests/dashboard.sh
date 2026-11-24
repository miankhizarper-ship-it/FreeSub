#!/usr/bin/env bash
#
# Task 05 — full user-dashboard verification.
#
# Runs, in order:
#
#   1. PHP lint over every file this task touched;
#   2. the whole unit suite (tests/run.php), which now includes the `dashboard`
#      suite;
#   3. the render harness against a seeded preview database;
#   4. the HTTP checks against a real server, three times:
#        APP_DEBUG=false, APP_DEBUG=true, and once more with the whole server
#        restarted from a fresh seed (so the checks never pass by accident on
#        leftovers from a previous run).
#
# It builds its own throwaway database under storage/ (gitignored) and deletes
# it on exit. It never touches storage/dev.sqlite or a real installation.
#
# Usage:  tests/dashboard.sh [port]

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${1:-8180}"
PHP_BIN="${PHP_BIN:-php}"
DB="${ROOT}/storage/dashboard-preview.sqlite"
LOG="/tmp/freesub-dashboard-${PORT}.log"
BASE="http://127.0.0.1:${PORT}"

FAILED=0
step() { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$*"; FAILED=1; }

cleanup() {
    if [ -n "${SERVER_PID:-}" ]; then
        kill "${SERVER_PID}" 2>/dev/null || true
        wait "${SERVER_PID}" 2>/dev/null || true
    fi
    rm -f "${DB}" "${DB}-wal" "${DB}-shm"
}

trap cleanup EXIT

reset_database() {
    rm -f "${DB}" "${DB}-wal" "${DB}-shm"
    DB_DRIVER=sqlite DB_SQLITE_PATH="${DB}" "${PHP_BIN}" "${ROOT}/database/install.php" >/dev/null 2>&1 \
        || { bad "install.php failed"; return 1; }
    DB_DRIVER=sqlite DB_SQLITE_PATH="${DB}" "${PHP_BIN}" "${ROOT}/database/migrate.php" >/dev/null 2>&1 \
        || { bad "migrate.php failed"; return 1; }
    APP_ENV=local DB_DRIVER=sqlite DB_SQLITE_PATH="${DB}" "${PHP_BIN}" "${ROOT}/tests/seed-dashboard.php" >/dev/null 2>&1 \
        || { bad "seed-dashboard.php failed"; return 1; }
    return 0
}

start_server() {
    local debug="$1"
    APP_ENV=local APP_DEBUG="${debug}" DB_DRIVER=sqlite DB_SQLITE_PATH="${DB}" \
        "${PHP_BIN}" -S "127.0.0.1:${PORT}" "${ROOT}/router.php" >"${LOG}" 2>&1 &
    SERVER_PID=$!

    for _ in $(seq 1 60); do
        if curl -fsS -o /dev/null "${BASE}/login.php" 2>/dev/null; then
            return 0
        fi
        sleep 0.2
    done

    bad "the server did not start (see ${LOG})"
    return 1
}

stop_server() {
    if [ -n "${SERVER_PID:-}" ]; then
        kill "${SERVER_PID}" 2>/dev/null || true
        wait "${SERVER_PID}" 2>/dev/null || true
    fi
    SERVER_PID=""
}

# ---------------------------------------------------------------------------
step "1. PHP lint"
lint_failed=0
while IFS= read -r file; do
    if ! output="$("${PHP_BIN}" -l "${file}" 2>&1)"; then
        bad "${file##*/}: ${output}"
        lint_failed=1
    fi
done < <(printf '%s\n' \
    "${ROOT}/dashboard.php" \
    "${ROOT}"/dashboard/*.php \
    "${ROOT}/preview-login.php" \
    "${ROOT}/api/check.php" \
    "${ROOT}/includes/bootstrap.php" \
    "${ROOT}/includes/auth.php" \
    "${ROOT}/includes/functions.php" \
    "${ROOT}/includes/models/Subdomain.php" \
    "${ROOT}/includes/services/Dashboard/"*.php \
    "${ROOT}/includes/services/Subdomain/SubdomainQuota.php" \
    "${ROOT}/includes/views/layouts/dashboard.php" \
    "${ROOT}/includes/views/partials/status-badge.php" \
    "${ROOT}"/includes/views/dashboard/*.php \
    "${ROOT}/tests/seed-dashboard.php" \
    "${ROOT}/tests/dashboard-render.php" \
    "${ROOT}/tests/dashboard-suite.php")

[ "${lint_failed}" = "0" ] && ok "every touched PHP file parses"

# ---------------------------------------------------------------------------
step "2. Unit suite (includes the dashboard suite)"
DB_DRIVER=sqlite DB_SQLITE_PATH="${DB}" "${PHP_BIN}" "${ROOT}/tests/run.php" >/tmp/freesub-unit.log 2>&1
tail -3 /tmp/freesub-unit.log

if grep -q "All .* assertions passed" /tmp/freesub-unit.log; then
    ok "unit suite passed"
else
    bad "unit suite failed — see /tmp/freesub-unit.log"
fi

# ---------------------------------------------------------------------------
step "3. Render harness"
reset_database || exit 1

# NOTE: php-wasm does not propagate exit codes, so the verdict comes from the
# harness's own summary line — never from `$?`.
DB_DRIVER=sqlite DB_SQLITE_PATH="${DB}" APP_ENV=local "${PHP_BIN}" "${ROOT}/tests/dashboard-render.php" >/tmp/freesub-render.log 2>&1

if grep -q "All .* render assertions passed" /tmp/freesub-render.log; then
    ok "render harness passed ($(grep -c '✓' /tmp/freesub-render.log) assertions)"
else
    bad "render harness failed — see /tmp/freesub-render.log"
    tail -20 /tmp/freesub-render.log
fi

# ---------------------------------------------------------------------------
for debug in false true; do
    step "4. HTTP checks (APP_DEBUG=${debug})"
    reset_database || exit 1
    start_server "${debug}" || exit 1

    if FREESUB_PREVIEW_BASE="${BASE}" DB_SQLITE_PATH="${DB}" python3 "${ROOT}/tests/http-dashboard.py" >/tmp/freesub-http-${debug}.log 2>&1; then
        ok "HTTP checks passed ($(grep -c '✓' /tmp/freesub-http-${debug}.log) checks)"
    else
        bad "HTTP checks failed — see /tmp/freesub-http-${debug}.log"
        tail -25 /tmp/freesub-http-${debug}.log
    fi

    stop_server
done

# ---------------------------------------------------------------------------
step "Result"

if [ "${FAILED}" = "0" ]; then
    echo "All dashboard checks passed."
    exit 0
fi

echo "Some dashboard checks failed (see the logs above)."
exit 1
