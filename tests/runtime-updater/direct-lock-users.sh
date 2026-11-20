#!/bin/sh
set -eu

php_bin=${1:-php}
web_user=${2:-www}
supervisor_bin=${3:-/www/server/panel/pyenv/bin}
test "$(id -u)" = 0
web_group=$(id -gn "$web_user")
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
work=$(mktemp -d)
control() { "$supervisor_bin/supervisorctl" -c "$work/supervisord.conf" "$@"; }
cleanup() {
    if test -S "$work/supervisord.sock"; then control shutdown >/dev/null || :; fi
    if test -s "$work/http.pid"; then kill "$(cat "$work/http.pid")" 2>/dev/null || :; fi
    rm -rf -- "$work"
}
trap cleanup EXIT
chmod 0755 "$work"

service_dir=app/common/service/update
mkdir -p "$work/$service_dir" "$work/config" "$work/scripts" "$work/public"
cp "$source_root/runtime-loader.php" "$source_root/direct-runtime-loader.php" "$work/"
cp "$source_root/$service_dir/DirectSiteUpdateService.php" "$work/$service_dir/"
cp "$source_root/scripts/start-ai-task-worker.sh" "$source_root/scripts/home-agent-php.sh" "$work/scripts/"
ln -s "$source_root/vendor" "$work/vendor"
touch "$work/config/install.lock"
chown "$web_user:$web_group" "$work/config/install.lock"
cp "$source_root/config/project.php" "$work/config/project.php"
cat > "$work/public/probe.php" <<'PHP'
<?php
try {
    $site = dirname(__DIR__);
    require $site.'/runtime-loader.php';
    echo $GLOBALS['AIGC_RUNTIME_GENERATION'] === $site ? 'site' : 'snapshot';
} catch (Throwable $error) {
    http_response_code(503);
    echo $error->getMessage();
    exit(1);
}
PHP
cat > "$work/think" <<'PHP'
<?php
require __DIR__.'/runtime-loader.php';
if ($argv[1] === 'virtualpay:maintain') exit;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function (): void { exit; });
while (true) sleep(1);
PHP
cat > "$work/supervisord.conf" <<EOF
[unix_http_server]
file=$work/supervisord.sock
[supervisord]
logfile=$work/supervisord.log
pidfile=$work/supervisord.pid
childlogdir=$work
[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface
[supervisorctl]
serverurl=unix://$work/supervisord.sock
[program:aigc]
command=/bin/sh $work/scripts/start-ai-task-worker.sh
directory=$work
environment=PHP_BIN="$php_bin"
user=root
autostart=false
startsecs=2
stopasgroup=true
killasgroup=true
stopwaitsecs=12
EOF

"$supervisor_bin/supervisord" -c "$work/supervisord.conf"
workers() { ps --ppid "$1" -o pid=,args= | awk '/\/think / && !/virtualpay:maintain/ { print $1 }'; }
check_workers() { test "$(workers "$1" | wc -l)" -eq 9; }
missing_processes() {
    for pid in "$@"; do test ! -d "/proc/$pid" || return 1; done
}
control start aigc
control status aigc | grep -q RUNNING
first_pid=$(control pid aigc)
check_workers "$first_pid"
first_workers=$(workers "$first_pid")
test "$(stat -c '%U:%G' "$work/runtime")" = "$web_user:$web_group"

port=$("$php_bin" -r '$socket=stream_socket_server("tcp://127.0.0.1:0"); echo explode(":",stream_socket_get_name($socket,false))[1];')
touch "$work/http.pid"
chown "$web_user:$web_group" "$work/http.pid"
runuser -u "$web_user" -- sh -c 'echo $$ > "$1"; exec "$2" -S "127.0.0.1:$3" -t "$4"' sh "$work/http.pid" "$php_bin" "$port" "$work/public" > "$work/http.log" 2>&1 &
url="http://127.0.0.1:$port/probe.php"
request() { curl -fsS --max-time 2 "$url"; }
for attempt in 1 2 3 4 5; do request >/dev/null 2>&1 && break; sleep 1; done
test "$(request)" = site

mkdir "$work/package"
printf '%s\n' '{"sha256":[]}' > "$work/package/signature.json"
runuser -u "$web_user" -- "$php_bin" -r '
    require $argv[1]."/app/common/service/update/DirectSiteUpdateService.php";
    $updater = new app\common\service\update\DirectSiteUpdateService($argv[1]);
    $result = $updater->apply($argv[1]."/package", ["version"=>"1.0.444"], []);
    if ($result["deployment"] !== "canonical") exit(1);
' "$work"

for name in site-update-intent.lock site-update.lock; do
    test "$(stat -c '%U:%G' "$work/runtime/$name")" = "$web_user:$web_group"
    stat -c '%i' "$work/runtime/$name" > "$work/$name.inode"
    chown root:root "$work/runtime/$name"
done
control restart aigc
control status aigc | grep -q RUNNING
second_pid=$(control pid aigc)
test "$first_pid" != "$second_pid"
missing_processes "$first_pid" $first_workers
check_workers "$second_pid"
second_workers=$(workers "$second_pid")
test "$(request)" = site
for name in site-update-intent.lock site-update.lock; do
    test "$(stat -c '%i' "$work/runtime/$name")" = "$(cat "$work/$name.inode")"
done
control stop aigc
control status aigc | grep -q STOPPED
missing_processes "$second_pid" $second_workers
test ! -f "$work/runtime/ai_task_worker.pid"
test "$(request)" = site
control start aigc
control status aigc | grep -q RUNNING
check_workers "$(control pid aigc)"
test "$(request)" = site
test ! -f "$work/runtime/direct-deployment.json"
echo 'PASS: Supervisor root start, stop and restart preserve web access and existing lock inodes'
