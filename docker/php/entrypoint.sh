#!/bin/sh
set -eu

APP_DIR=/var/www/html

if [ "$(id -u)" = "0" ]; then
    mkdir -p \
        "$APP_DIR/runtime/sessions" \
        "$APP_DIR/public/uploads" \
        "$APP_DIR/public/storage" \
        "$APP_DIR/public/qrcode"
    chown www-data:www-data \
        "$APP_DIR/runtime" \
        "$APP_DIR/runtime/sessions" \
        "$APP_DIR/public/uploads" \
        "$APP_DIR/public/storage" \
        "$APP_DIR/public/qrcode"

    # PHP-FPM needs its root master process to open logs and then drops its
    # workers to www-data. All CLI processes can drop privileges immediately.
    if [ "${1:-fpm}" != "fpm" ]; then
        exec gosu www-data "$0" "$@"
    fi
fi

cd "$APP_DIR"

case "${1:-fpm}" in
    fpm)
        exec php-fpm -F
        ;;
    initialize)
        exec php /usr/local/bin/likeadmin-initialize.php
        ;;
    ai-worker)
        shift
        exec php think ai:task-worker --worker=result --sleep=1 --lease=90 --batch=20 "$@"
        ;;
    canvas-worker)
        shift
        exec php think canvas:subagent-worker --worker=subagent --sleep=1 --lease=180 "$@"
        ;;
    scheduler)
        exec /usr/local/bin/likeadmin-scheduler
        ;;
    *)
        exec "$@"
        ;;
esac
