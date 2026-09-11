#!/bin/sh
set -eu
DRAMA_SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PHP_BIN=${PHP_BIN:-/www/server/php/80/bin/php}
cd "$DRAMA_SERVER_DIR"
exec "$PHP_BIN" "$DRAMA_SERVER_DIR/think" short-drama:episode-worker
