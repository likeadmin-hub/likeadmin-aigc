#!/usr/bin/env sh
# Preserve php.ini restrictions except the one function required by bounded decoders.
set -eu
cd "$(dirname "$0")/.."
AGENT_PHP_BIN="${AGENT_PHP_BIN:-php}"
AGENT_DISABLED="$($AGENT_PHP_BIN -r '$items=array_filter(array_map("trim",explode(",",ini_get("disable_functions"))),static fn($name)=>$name!=="proc_open");echo implode(",",$items);')"
exec "$AGENT_PHP_BIN" -d "disable_functions=$AGENT_DISABLED" "$@"
