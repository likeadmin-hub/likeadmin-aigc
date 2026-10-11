#!/usr/bin/env python3
"""Run real system-update services against a private, disposable MySQL 8 database.

No host ports, existing database, worker or shared checkout writes are used.
Reuse the Docker/environment setup pattern from install-schema-parity.integration.py.
"""
import argparse
import os
import pathlib
import re
import subprocess
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--business', action='store_true', help='Run PHP tests excluding built asset contracts')
parser.add_argument('--full-suite', action='store_true', help='Run the complete PHP suite after assets are ready')
parser.add_argument('--updater', action='store_true', help='Run real preflight/apply success and failure cases')
args = parser.parse_args()
if not any(vars(args).values()):
    args.updater = True
name = 'aigc-system-fixture-' + uuid.uuid4().hex[:10]
network = name + '-network'
php_containers = []
image = os.getenv('INSTALL_PARITY_PHP_IMAGE', 'likeadmin-install-worker-test:local')
payload = root.parent / 'production-composer'

def run(*command, data=None, quiet=False):
    result = subprocess.run(command, input=data, text=True, capture_output=True)
    if result.stdout and not quiet:
        print(result.stdout, end='', flush=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or 'Command failed: ' + ' '.join(command))
    return result.stdout

def sql(database, content):
    return run('docker', 'exec', '-i', name, 'mysql', '-h127.0.0.1', '-uroot',
               '--default-character-set=utf8mb4', '-N', database, data=content, quiet=True)

def php(database):
    container = name + '-php-' + database
    php_containers.append(container)
    command = ['docker', 'run', '--rm', '--name', container, '--network', network,
        '-v', str(root) + ':/repo:ro', '--tmpfs', '/repo/runtime', '--tmpfs', '/repo/public/uploads', '-w', '/repo',
        '-e', 'SYSTEM_SERVICE_DISPOSABLE=1', '-e', 'INSTALL_PARITY_DISPOSABLE=1',
        '-e', 'PHP_DATABASE_HOSTNAME=mysql', '-e', 'PHP_DATABASE_DATABASE=' + database,
        '-e', 'PHP_DATABASE_USERNAME=root', '-e', 'PHP_DATABASE_PASSWORD=',
        '-e', 'PHP_DATABASE_PREFIX=la_', '-e', 'PHP_DATABASE_HOSTPORT=3306', '-e', 'PHP_CACHE_DRIVER=file']
    if args.updater:
        command += ['-v', str(payload.resolve()) + ':/production:ro']
    for terminal in ['pc', 'tenant', 'platform', 'uniapp']:
        path = root.parent / 'web' / terminal
        if path.is_dir():
            command += ['-v', str(path.resolve()) + ':/' + terminal + ':ro']
    return command + [image, 'php']

try:
    run('docker', 'network', 'create', network)
    run('docker', 'run', '-d', '--name', name, '--network', network, '--network-alias', 'mysql',
        '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0',
        '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci')
    for _ in range(60):
        probe = subprocess.run(['docker', 'exec', name, 'mysqladmin', '-h127.0.0.1', 'ping', '--silent'], capture_output=True)
        if probe.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Disposable MySQL startup timed out')
    install = (root / 'public/install/db/like.sql').read_text()
    cases = ['business'] if args.business or args.full_suite else []
    if args.updater:
        if not (payload / 'vendor/services.php').is_file():
            raise RuntimeError('Complete production Composer payload is required')
        cases += ['success', 'dependency-failure', 'finalize-failure', 'metadata-failure']
    for case in cases:
        database = 'fixture_' + case.replace('-', '_')
        sql('mysql', 'CREATE DATABASE ' + database + ';')
        sql(database, install)
        if case == 'business':
            command = php(database) + ['vendor/bin/phpunit', '--do-not-cache-result', '-c', 'phpunit.xml.dist']
            if args.business:
                excluded = []
                for path in (root / 'tests').rglob('*Test.php'):
                    text = path.read_text()
                    if re.search(r'CompiledAssets::(?!source\b)', text) or re.search(r'public/(?:_nuxt|pc|admin|platform|mobile|media|mp-weixin)', text):
                        match = re.search(r'\bclass\s+(\w+)', text)
                        if match:
                            excluded.append(match.group(1))
                command += ['--filter', '^(?!.*(?:' + '|'.join(sorted(set(excluded))) + ')).*$']
                print('Excluded asset contract classes: ' + str(len(set(excluded))), flush=True)
            run(*command)
        else:
            output = run(*php(database), 'tests/runtime-updater/system-service.fixture.php', case)
            if 'PASS real SystemPackageUpdateService ' + case not in output:
                raise RuntimeError('Fixture exited without a verified success result: ' + case)
        print('PASS disposable database case: ' + case, flush=True)
finally:
    for container in php_containers:
        subprocess.run(['docker', 'rm', '-f', '-v', container], capture_output=True)
    subprocess.run(['docker', 'rm', '-f', '-v', name], capture_output=True)
    subprocess.run(['docker', 'network', 'rm', network], capture_output=True)
    print('Cleaned disposable MySQL container and private network; no host ports were published.', flush=True)
