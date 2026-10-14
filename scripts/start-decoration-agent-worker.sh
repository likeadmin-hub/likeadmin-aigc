#!/usr/bin/env bash
set -euo pipefail
DECORATION_PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DECORATION_PHP_BIN="${DECORATION_PHP_BIN:-php}"
cd "$DECORATION_PROJECT_ROOT"
exec "$DECORATION_PHP_BIN" think decoration:agent-worker "$@"
