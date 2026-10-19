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
import sys
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('--dev-vendor', type=pathlib.Path, help='Read-only PHPUnit vendor for a canonical no-dev checkout')
parser.add_argument('--phpunit-phar', type=pathlib.Path)
parser.add_argument('--canonical', action='store_true')
parser.add_argument('--legacy402', action='store_true')
parser.add_argument('--baseline',type=pathlib.Path)
parser.add_argument('--business', action='store_true', help='Run PHP tests excluding built asset contracts')
parser.add_argument('--cases', nargs='+')
parser.add_argument('--archive',type=pathlib.Path)
parser.add_argument('--db-tests', action='store_true')
parser.add_argument('--standalone', action='store_true')
parser.add_argument('--app-packages', action='store_true')
parser.add_argument('--full-suite', action='store_true', help='Run the complete PHP suite after assets are ready')
parser.add_argument('--companions', action='store_true', help='Run all independent server integration/protocol fixtures')
parser.add_argument('--companion-start', type=int, default=0, help='Resume companion fixtures at a zero-based index')
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
    command = ['docker', 'run', '--rm', '--name', container, '--network', ('container:'+name if database=='x_cn' else network),
        '-v', str(root) + ':/repo:ro', '--tmpfs', '/repo/runtime', '--tmpfs', '/repo/public/uploads', '-w', '/repo',
        '-e', 'SYSTEM_SERVICE_DISPOSABLE=1', '-e', 'INSTALL_PARITY_DISPOSABLE=1',
        '-e', 'PHP_DATABASE_HOSTNAME='+('127.0.0.1' if database=='x_cn' else 'mysql'), '-e', 'PHP_DATABASE_DATABASE=' + database,
        '-e', 'PHP_DATABASE_USERNAME=root', '-e', 'PHP_DATABASE_PASSWORD=',
        '-e', 'FIXTURE_APP_PACKAGES='+('1' if args.app_packages else '0'), '-e', 'PHP_DATABASE_PREFIX=la_', '-e', 'PHP_DATABASE_HOSTPORT=3306', '-e', 'PHP_CACHE_DRIVER=file']
    installed=root.parents[4]/'server/config/install.lock'
    if installed.is_file():
        command += ['-v',str(installed)+':/repo/config/install.lock:ro']
    if args.db_tests:
        command += ['-e','PIC_LIPSYNC_TEST_DATABASE=x_cn']
        for flag in ['SHORT_DRAMA_DB_TESTS','SHORT_DRAMA_STORY_DB_TESTS','SHORT_DRAMA_TASK_DB_TESTS','SHORT_DRAMA_NEURON_LOCAL_ROUTING_TESTS','DIGITAL_HUMAN_MYSQL_TEST','PIC_LIPSYNC_MYSQL_TEST','PC_WECHAT_MYSQL_TEST','AIGC_ENHANCE_CONFIG_DB_TESTS']:
            command += ['-e',flag+'=1']
    if args.dev_vendor:
        command += ['-v', str(args.dev_vendor.resolve()) + ':/repo/vendor:ro']
    if args.archive:
        command += ['-v',str(args.archive.resolve())+':/official.zip:ro','-e','FIXTURE_OFFICIAL_ARCHIVE=/official.zip']
    if args.baseline:
        command += ['-v',str(args.baseline.resolve())+':/baseline:ro']
    if args.phpunit_phar:
        command += ['-v', str(args.phpunit_phar.resolve()) + ':/phpunit.phar:ro']
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
    install_root = args.baseline.resolve() if args.updater and args.baseline else root
    install = (install_root / 'public/install/db/like.sql').read_text()
    cases = ['business'] if args.business or args.full_suite else []
    if args.standalone: cases += ['standalone']
    if args.updater:
        if not (payload / 'vendor/services.php').is_file():
            raise RuntimeError('Complete production Composer payload is required')
        cases += [('legacy402-' if args.legacy402 else ('canonical-' if args.canonical else '')) + value for value in ['success', 'dependency-failure', 'finalize-failure', 'metadata-failure', 'commit-reply-failure', 'precommit-exit'] + ([] if args.legacy402 else ['confirm-failure'])]
    companions = ['tests/system-update/integration.php','tests/license/cache.integration.php',
        'tests/release-306-migration.integration.php','tests/short-drama-styles.integration.php',
        'tests/short-drama-skill.integration.php','tests/user-tool-pins.integration.php',
        'tests/pc-home-shortcuts.integration.php','tests/license/protocol.php',
        'tests/system-update/protocol.php','tests/framework-oem/run.php',
        'tests/runtime-updater/system-service.lifecycle.php','tests/release-sql-replay.integration.php',
        'tests/runtime-updater/release-392-migrations.integration.php']
    if args.companions:
        cases += ['companion_' + str(index) for index in range(args.companion_start, len(companions))]
    if args.cases:
        selected = {re.sub(r'^(canonical|legacy402)-', '', case) for case in args.cases}
        cases = [case for case in cases if re.sub(r'^(canonical|legacy402)-', '', case) in selected]
        if not cases:
            raise RuntimeError('No requested system-service cases matched; refusing an empty test run')
    for case in cases:
        database = 'x_cn' if case=='standalone' or (case=='business' and args.db_tests) else 'fixture_' + case.replace('-', '_')
        sql('mysql', 'CREATE DATABASE ' + database + ';')
        sql(database, install)
        if case.startswith('companion_'):
            script = companions[int(case.split('_')[1])]
            sql(database, "INSERT INTO la_tenant (id,sn,name,create_time) VALUES (901,'companion901','Disposable integration',1);")
            setup = ''
            if script == 'tests/system-update/integration.php':
                setup = "mkdir('/tmp/system-protocol-site/public',0755,true); define('ROOT_PATH','/tmp/system-protocol-site/public');"
            if script == 'tests/release-sql-replay.integration.php':
                setup = "mkdir('/repo/runtime/system_update_staging/replay',0755,true); foreach(glob('/repo/upgrade/*.sql') as $file) copy($file,'/repo/runtime/system_update_staging/replay/'.basename($file)); $argv=[__FILE__,'/repo/runtime/system_update_staging/replay'];"
            code = "$GLOBALS['acceptance_integration_finished']=false; register_shutdown_function(static function(){if(empty($GLOBALS['acceptance_integration_finished'])){fwrite(STDERR,'Integration did not reach completion');exit(1);}});" + setup + 'try { require ' + repr(script) + "; } catch(Throwable $error) { fwrite(STDERR,(string)$error); exit(1); } $GLOBALS['acceptance_integration_finished']=true; echo " + repr('PASS completed integration: ' + script) + ", PHP_EOL;"
            run(*php(database), '-r', code)
        elif case == 'standalone':
            seeded=run(*php(database),'tests/runtime-updater/business-seed.php')
            if 'PASS deterministic offline catalogue' not in seeded:raise RuntimeError('Disposable seed did not complete')
            failed=[]
            for path in sorted((root/'tests').rglob('*Test.php')):
                if re.search(r'extends\s+(?:\\?PHPUnit\\Framework\\)?TestCase\b',path.read_text()):continue
                try:run(*php(database),str(path.relative_to(root)))
                except RuntimeError as error:failed.append(str(path.relative_to(root))+': '+str(error));print('FAIL '+failed[-1],flush=True)
            if failed:raise RuntimeError('Standalone failures: '+str(len(failed))+'\n'+'\n'.join(failed))
        elif case == 'business':
            if args.db_tests:
                seeded=run(*php(database),'tests/runtime-updater/business-seed.php')
                if 'PASS deterministic offline catalogue' not in seeded:raise RuntimeError('Disposable seed did not complete')
            files=[]
            for path in sorted((root/'tests').rglob('*Test.php')):
                if re.search(r'extends\s+(?:\\?PHPUnit\\Framework\\)?TestCase\b',path.read_text()):files.append('/repo/'+str(path.relative_to(root)))
            xml='<phpunit bootstrap="/repo/tests/bootstrap.php"><testsuites><testsuite name="application">'+''.join('<file>'+f+'</file>' for f in files)+'</testsuite></testsuites></phpunit>'
            runner = 'file_put_contents("/repo/runtime/phpunit-isolated.xml",'+repr(xml)+'); passthru(escapeshellarg(PHP_BINARY)." ".escapeshellarg('+repr('/phpunit.phar' if args.phpunit_phar else '/repo/vendor/bin/phpunit')+')." --do-not-cache-result -c /repo/runtime/phpunit-isolated.xml",$status);exit($status);'
            command = php(database) + ['-r',runner]
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
    if args.companions:
        run(sys.executable, str(root / 'tests/system-update/composer-packaging.py'))
finally:
    for container in php_containers:
        subprocess.run(['docker', 'rm', '-f', '-v', container], capture_output=True)
    subprocess.run(['docker', 'rm', '-f', '-v', name], capture_output=True)
    subprocess.run(['docker', 'network', 'rm', network], capture_output=True)
    print('Cleaned disposable MySQL container and private network; no host ports were published.', flush=True)
