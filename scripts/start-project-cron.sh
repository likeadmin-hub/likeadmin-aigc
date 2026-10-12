#!/bin/sh
# Project-local cron loop; never depend on the shared /usr/bin/php version.
set -eu
SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PHP_BIN=${PHP_BIN:-/www/server/php/82/bin/php}
[ -x "$PHP_BIN" ] || exit 127
cd "$SERVER_DIR"
child=""
cleanup() {
    trap - TERM INT EXIT
    if [ -n "$child" ]; then
        kill -TERM "$child" 2>/dev/null || true
        wait "$child" 2>/dev/null || true
    fi
}
trap 'exit 143' TERM
trap 'exit 130' INT
trap cleanup EXIT
while :; do
    sleep 60 &
    child=$!
    wait "$child"
    "$PHP_BIN" "$SERVER_DIR/think" crontab &
    child=$!
    # A failed cron iteration must not disable all future scheduled jobs.
    wait "$child" || true
    child=""
done
