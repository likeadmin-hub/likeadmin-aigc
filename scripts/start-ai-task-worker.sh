#!/bin/sh
# Baota Supervisor entry: supervise all three workers as one process group.
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
EPISODE_MATCH="$SERVER_DIR/think short-drama:episode-worker"
PLANNING_MATCH="$SERVER_DIR/think short-drama:planning-worker"

mkdir -p "$LOG_DIR"

log_startup() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" >> "$START_LOG"
}

log_startup "starting worker: server=$SERVER_DIR php=$PHP_BIN pid=$$"
cd "$SERVER_DIR"
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

# One Supervisor entry owns the general task worker and both short-drama
# queues. Keep the children in this script's process group so one restart
# starts the complete worker set and no queue is silently left behind.
pids=""
cleanup() {
    trap - TERM INT EXIT
    for pid in $pids; do kill -TERM "$pid" 2>/dev/null || true; done
    for pid in $pids; do wait "$pid" 2>/dev/null || true; done
}
trap 'exit 143' TERM
trap 'exit 130' INT
trap cleanup EXIT

"$PHP_BIN" "$SERVER_DIR/think" ai:task-worker --worker=result --sleep=1 --lease=90 --batch=20 >> "$LOG_FILE" 2>&1 &
pids="$pids $!"
"$PHP_BIN" "$SERVER_DIR/think" short-drama:episode-worker >> "$LOG_FILE" 2>&1 &
pids="$pids $!"
"$PHP_BIN" "$SERVER_DIR/think" short-drama:planning-worker >> "$LOG_FILE" 2>&1 &
pids="$pids $!"
log_startup "started workers: $pids"
# POSIX sh (including dash and older Bash in sh mode) has no wait -n.
# If any child stops, exit nonzero so Supervisor restarts the complete group.
while :; do
    for pid in $pids; do
        if ! kill -0 "$pid" 2>/dev/null; then
            status=0
            wait "$pid" || status=$?
            log_startup "ERROR: worker pid=$pid exited status=$status; restarting group"
            exit 1
        fi
    done
    sleep 1
done
