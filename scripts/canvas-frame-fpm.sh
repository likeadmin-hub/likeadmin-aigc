#!/usr/bin/env sh
# A dedicated FPM master for the authenticated canvas captureFrame route only.
# Never point general site traffic at this socket. All other restrictions remain.
set -eu
CANVAS_PHP_BIN="${CANVAS_PHP_BIN:-php}"
CANVAS_FPM_BIN="${CANVAS_FPM_BIN:-php-fpm}"
: "${CANVAS_FPM_CONFIG:?Set the dedicated FPM configuration path}"
CANVAS_DISABLED="$($CANVAS_PHP_BIN -r '$items=array_filter(array_map("trim",explode(",",ini_get("disable_functions"))),static fn($name)=>$name!=="proc_open");echo implode(",",$items);')"
exec "$CANVAS_FPM_BIN" -F -y "$CANVAS_FPM_CONFIG" -d "disable_functions=$CANVAS_DISABLED"
