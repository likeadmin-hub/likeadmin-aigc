#!/bin/sh
# Local Baota entry for this project's isolated PHP 8.2 process group.
set -eu
SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CONFIG="$SERVER_DIR/runtime/php82-supervisord.conf"
SUPERVISOR=/www/server/panel/pyenv/bin/supervisord
CONTROL=/www/server/panel/pyenv/bin/supervisorctl
[ -f "$CONFIG" ] && [ -x "$SUPERVISOR" ] && [ -x "$CONTROL" ] || exit 127
pid=$("$CONTROL" -c "$CONFIG" pid 2>/dev/null || true)
case "$pid" in
    ''|*[!0-9]*) ;;
    *)
        command=$(ps -p "$pid" -o command= 2>/dev/null || true)
        case "$command" in
            *"$CONFIG"*)
                "$CONTROL" -c "$CONFIG" shutdown
                elapsed=0
                while kill -0 "$pid" 2>/dev/null; do
                    [ "$elapsed" -lt 360 ] || exit 1
                    sleep 1
                    elapsed=$((elapsed + 1))
                done
                ;;
            *) exit 1 ;;
        esac
        ;;
esac
exec "$SUPERVISOR" -n -c "$CONFIG"
