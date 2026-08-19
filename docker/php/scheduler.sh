#!/bin/sh
set -eu

trap 'exit 0' INT TERM

while :; do
    php /var/www/html/think crontab || true
    delay=$((60 - $(date +%s) % 60))
    sleep "$delay" &
    wait $!
done
