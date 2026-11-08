#!/usr/bin/env python3
"""Run app switch regressions in a new MySQL container, never an application DB."""
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[2]
name = 'likeadmin-app-access-' + uuid.uuid4().hex[:12]
image = os.environ.get('INSTALL_PARITY_PHP_IMAGE', 'likeadmin-install-worker-test:local')

def run(*args, **kwargs):
    return subprocess.run(args, check=True, text=True, capture_output=True, **kwargs).stdout.strip()

try:
    run('docker', 'run', '-d', '--name', name, '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
        '-e', 'MYSQL_DATABASE=app_access_fixture', 'mysql:8.0', '--skip-log-bin')
    for _ in range(60):
        result = subprocess.run(['docker', 'exec', name, 'mysqladmin', '-h127.0.0.1', 'ping', '--silent'], capture_output=True)
        if result.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Private MySQL startup timed out')
    identity = run('docker', 'exec', name, 'mysql', '-uroot', '-N', '-e', 'SELECT @@server_uuid')
    with tempfile.TemporaryDirectory(prefix='likeadmin-app-access-') as temporary:
        mask = pathlib.Path(temporary) / 'mask.env'
        mask.write_text('')
        output = run('docker', 'run', '--rm', '--network', 'container:' + name,
                     '-e', 'APP_ACCESS_DISPOSABLE_TEST=1', '-e', 'APP_ACCESS_DB_UUID=' + identity,
                     '-v', str(root) + ':/app', '-v', str(mask) + ':/app/.env:ro',
                     '--tmpfs', '/app/runtime', '-w', '/app', image,
                     'php', 'tests/app-access/switch.integration.php')
        print(output, flush=True)
except subprocess.CalledProcessError as error:
    raise RuntimeError((error.stderr or '') + (error.stdout or '')) from error
finally:
    subprocess.run(['docker', 'rm', '-f', '-v', name], capture_output=True)
