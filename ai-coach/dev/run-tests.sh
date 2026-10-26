#!/usr/bin/env bash
# LOCAL TESTING ONLY. Starts a fake Claude server and the coach, then runs dev/test.php.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$(dirname "$HERE")"
WORK="$(mktemp -d)"
export MOCK_LOG="$WORK/mock.jsonl"; : > "$MOCK_LOG"
export TEST_DATA_DIR="$WORK/private"
export MOCK_BASE="http://127.0.0.1:18099"
export APP_BASE="http://127.0.0.1:18098"
export APP_BASE_BADDIR="http://127.0.0.1:18097"
export PHP_CLI_SERVER_WORKERS=4
PIDS=()
cleanup(){ for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; rm -rf "$WORK"; }
trap cleanup EXIT

php -S 127.0.0.1:18099 "$HERE/mock-claude.php" >/dev/null 2>&1 & PIDS+=($!)
php -d auto_prepend_file="$HERE/dev-site.php" -S 127.0.0.1:18098 -t "$APP" >"$WORK/app.log" 2>&1 & PIDS+=($!)
# second copy whose data folder sits INSIDE the web folder (must be refused)
TEST_DATA_DIR="$APP/dev-baddir-test" php -d auto_prepend_file="$HERE/dev-site.php" -S 127.0.0.1:18097 -t "$APP" >/dev/null 2>&1 & PIDS+=($!)
sleep 1.5
php "$HERE/test.php"; RC=$?
rm -rf "$APP/dev-baddir-test"
exit $RC
