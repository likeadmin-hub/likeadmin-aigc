#!/bin/sh
# Optional standalone supervisor entry; do not also enable it in the shared worker group.
set -eu
OEM_SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PHP_BIN=${PHP_BIN:-/www/server/php/82/bin/php}
cd "$OEM_SERVER_DIR"
exec "$PHP_BIN" "$OEM_SERVER_DIR/think" brand:worker --sleep=3
