#!/usr/bin/env python3
"""Run image history regressions against a new, identity-checked disposable MySQL."""
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[2]
name = 'likeadmin-image-reference-' + uuid.uuid4().hex[:12]
image = os.environ.get('INSTALL_PARITY_PHP_IMAGE', 'likeadmin-install-worker-test:local')

def run(*args):
    result = subprocess.run(args, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result.stdout.strip()

try:
    run('docker', 'run', '-d', '--name', name, '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
        '-e', 'MYSQL_DATABASE=image_reference_fixture', 'mysql:8.0', '--skip-log-bin')
    for _ in range(60):
        if subprocess.run(['docker', 'exec', name, 'mysqladmin', '-h127.0.0.1', 'ping', '--silent'], capture_output=True).returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Private MySQL startup timed out')
    identity = run('docker', 'exec', name, 'mysql', '-uroot', '-N', '-e', 'SELECT @@server_uuid')
    with tempfile.TemporaryDirectory(prefix='image-reference-') as temporary:
        mask = pathlib.Path(temporary) / 'mask.env'
        mask.write_text('')
        print(run('docker', 'run', '--rm', '--network', 'container:' + name,
                  '-e', 'IMAGE_REFERENCE_DISPOSABLE_TEST=1', '-e', 'IMAGE_REFERENCE_DB_UUID=' + identity,
                  '-v', str(root) + ':/app:ro', '-v', str(mask) + ':/app/.env:ro',
                  '--tmpfs', '/app/runtime', '--tmpfs', '/app/public/uploads', '-w', '/app', image,
                  'php', 'tests/image-reference-display/integration.php'), flush=True)
finally:
    subprocess.run(['docker', 'rm', '-f', '-v', name], capture_output=True)
