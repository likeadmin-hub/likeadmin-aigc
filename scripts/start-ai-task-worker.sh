#!/bin/sh
# Baota Supervisor entry: replace any stale result worker for this checkout,
# then exec PHP so Supervisor tracks the actual worker PID.
set -eu

SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PID_FILE="$SERVER_DIR/runtime/ai_task_worker.pid"
LOG_DIR="$SERVER_DIR/runtime/log"
LOG_FILE="$LOG_DIR/ai_task_worker.log"
START_LOG="$LOG_DIR/ai_task_worker_start.log"
PHP_BIN=${PHP_BIN:-/www/server/php/80/bin/php}
# Match the exact project Think command. PHP may be invoked as either `php`
# or an absolute Baota PHP path, so the executable itself must not be part of
# the process match.
WORKER_MATCH="$SERVER_DIR/think ai:task-worker"

mkdir -p "$LOG_DIR"

log_startup() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" >> "$START_LOG"
}

log_startup "starting worker: server=$SERVER_DIR php=$PHP_BIN pid=$$"
if [ ! -x "$PHP_BIN" ]; then
    log_startup "ERROR: PHP executable not found or not executable: $PHP_BIN"
    exit 127
fi
if [ ! -f "$SERVER_DIR/think" ]; then
    log_startup "ERROR: Think command not found: $SERVER_DIR/think"
    exit 127
fi

stop_pid() {
    target="$1"
    [ -n "$target" ] || return 0
    case "$target" in *[!0-9]*) return 0 ;; esac
    kill -0 "$target" 2>/dev/null || return 0
    command=$(ps -p "$target" -o command= 2>/dev/null || true)
    case "$command" in
        *"$WORKER_MATCH"*)
            kill -TERM "$target" 2>/dev/null || true
            i=0
            while kill -0 "$target" 2>/dev/null && [ "$i" -lt 20 ]; do sleep 1; i=$((i + 1)); done
            kill -0 "$target" 2>/dev/null && kill -KILL "$target" 2>/dev/null || true
            ;;
    esac
}

if [ -f "$PID_FILE" ]; then
    stop_pid "$(cat "$PID_FILE" 2>/dev/null || true)"
fi

# Only exact commands for this absolute checkout are eligible for termination.
ps -axo pid=,command= | while IFS= read -r line; do
    pid=$(printf '%s\n' "$line" | awk '{print $1}')
    command=${line#"$pid"}
    case "$command" in *"$WORKER_MATCH"*) stop_pid "$pid" ;; esac
done

rm -f "$PID_FILE"
export AI_TASK_WORKER_PID_FILE="$PID_FILE"
echo "$$" > "$PID_FILE"
log_startup "exec: $PHP_BIN $SERVER_DIR/think ai:task-worker"
exec "$PHP_BIN" "$SERVER_DIR/think" ai:task-worker --worker=result --sleep=1 --lease=90 --batch=20 >> "$LOG_FILE" 2>&1
