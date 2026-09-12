#!/bin/sh
# One Supervisor entry, two independent PHP workers. Neither worker waits for
# the other's queue; this shell only monitors their process lifecycles.
set -eu

SERVER_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
LOG_DIR="$SERVER_DIR/runtime/log"
PHP_BIN=${PHP_BIN:-/www/server/php/80/bin/php}
mkdir -p "$LOG_DIR"
START_LOG="$LOG_DIR/ai_task_worker_start.log"

log_startup() {
    printf '[%s] %s\n' "$(date '+%F %T')" "$*" >> "$START_LOG"
}

if [ ! -x "$PHP_BIN" ] || [ ! -f "$SERVER_DIR/think" ]; then
    log_startup "ERROR: missing PHP executable or Think entry"
    exit 127
fi
if ! command -v flock >/dev/null 2>&1; then
    log_startup "ERROR: flock is required to prevent duplicate worker groups"
    exit 127
fi

# Keep the lock file: removing it can create two independently locked inodes.
exec 9>"$SERVER_DIR/runtime/ai_task_workers.lock"
if ! flock -n 9; then
    log_startup "ERROR: worker group is already running for this checkout"
    exit 1
fi

# Do not silently interrupt paid requests from legacy standalone workers.
existing=$(ps -eo pid=,args= | awk -v think="$SERVER_DIR/think" '
    $3 == think && ($4 == "ai:task-worker" || $4 == "short-drama:episode-worker") { print $1 }
')
if [ -n "$existing" ]; then
    log_startup "ERROR: stop legacy standalone workers before starting this group; pids=$existing"
    exit 1
fi

result_pid=''
episode_pid=''
cleanup() {
    trap '' TERM INT
    log_startup "stopping worker group pid=$$"
    for child in "$result_pid" "$episode_pid"; do
        if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || true; fi
    done
    # Allow in-flight model calls to settle. Supervisor supplies the outer
    # stop timeout and must kill the process group, not only this shell.
    for child in "$result_pid" "$episode_pid"; do
        if [ -n "$child" ]; then wait "$child" 2>/dev/null || true; fi
    done
}
trap 'exit 0' TERM INT
trap cleanup EXIT
# The old PHP worker must not own or unlink the launcher PID file.
unset AI_TASK_WORKER_PID_FILE
cd "$SERVER_DIR"

start_result() {
    "$PHP_BIN" "$SERVER_DIR/think" ai:task-worker --worker=result --sleep=1 --lease=90 --batch=20 >> "$LOG_DIR/ai_task_worker.log" 2>&1 &
    result_pid=$!
    log_startup "result worker started pid=$result_pid"
}
start_episode() {
    "$PHP_BIN" "$SERVER_DIR/think" short-drama:episode-worker >> "$LOG_DIR/short_drama_episode_worker.log" 2>&1 &
    episode_pid=$!
    log_startup "episode worker started pid=$episode_pid"
}

start_result
start_episode
while :; do
    # Backoff bounds restarts even if a child fails immediately.
    sleep 3 &
    wait $! || true
    if ! kill -0 "$result_pid" 2>/dev/null; then
        wait "$result_pid" 2>/dev/null || true
        start_result
    fi
    if ! kill -0 "$episode_pid" 2>/dev/null; then
        wait "$episode_pid" 2>/dev/null || true
        start_episode
    fi
done
