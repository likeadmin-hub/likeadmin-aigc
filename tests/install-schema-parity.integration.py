#!/usr/bin/env python3
"""Validate fresh/legacy/custom-prefix installs in disposable containers.

SQL checks: python3 tests/install-schema-parity.integration.py
Fresh/custom install and real installer checks: add --fresh-only --workers.
The default legacy fixture checks only its explicitly listed historical repairs.
Real installer/workers: set INSTALL_PARITY_PHP_IMAGE to a PHP 8.2 image with
PDO MySQL and pcntl, then add --workers. --full-suite also runs PHPUnit.
"""
import pathlib
import os
import re
import subprocess
import sys
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[1]
name = 'ikjf3n-parity-' + uuid.uuid4().hex[:10]
network = name + '-network'
workers = []
php_image = os.getenv('INSTALL_PARITY_PHP_IMAGE')
with_workers = '--workers' in sys.argv or '--full-suite' in sys.argv
if with_workers and not php_image:
    raise RuntimeError('Set INSTALL_PARITY_PHP_IMAGE to a PHP 8.2 image with PDO MySQL and pcntl')

def run(*args, data=None):
    result = subprocess.run(args, input=data, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or result.stdout.strip())
    return result.stdout

def sql(database, text):
    return run('docker', 'exec', '-i', name, 'mysql', '-h127.0.0.1', '--default-character-set=utf8mb4', '-uroot', '-N', database, data=text)

def php_args(database, prefix):
    arguments = ['--network', network, '-v', str(root) + ':/repo:ro', '--tmpfs', '/repo/runtime', '-w', '/repo',
            '-e', 'INSTALL_PARITY_DISPOSABLE=1', '-e', 'PHP_DATABASE_HOSTNAME=mysql',
            '-e', 'PHP_DATABASE_DATABASE=' + database, '-e', 'PHP_DATABASE_PREFIX=' + prefix,
            '-e', 'PHP_DATABASE_USERNAME=root', '-e', 'PHP_DATABASE_PASSWORD=',
            '-e', 'PHP_DATABASE_HOSTPORT=3306', '-e', 'PHP_CACHE_DRIVER=file']
    # Support the split checkout when older contract tests inspect sibling roots.
    frontend = root.parent / 'web'
    for terminal in ['pc', 'uniapp', 'tenant', 'platform']:
        if (frontend / terminal).is_dir():
            arguments += ['-v', str((frontend / terminal).resolve()) + ':/' + terminal + ':ro']
    return arguments

def smoke_workers(database, prefix):
    print(run('docker', 'run', '--rm', *php_args(database, prefix), php_image,
              'php', 'think', '--once'))
    worker = name + '-' + database
    workers.append(worker)
    run('docker', 'run', '-d', '--name', worker, *php_args(database, prefix), php_image,
        'php', 'think', 'ai:task-worker', '--sleep=1')
    time.sleep(3)
    logs = run('docker', 'logs', worker)
    assert run('docker', 'inspect', '-f', '{{.State.Running}}', worker).strip() == 'true', logs
    run('docker', 'stop', '-t', '5', worker)
    logs = run('docker', 'logs', worker)
    assert run('docker', 'inspect', '-f', '{{.State.ExitCode}}', worker).strip() == '0', logs
    assert 'AI result worker stopped:' in logs, logs
    print(database + ': real result daemon remains running and stops cleanly')
    print(run('docker', 'run', '--rm', *php_args(database, prefix), php_image,
              'php', 'tests/install-worker-smoke.php'))

try:
    if with_workers:
        run('docker', 'run', '--rm', php_image, 'php', '-r',
            'if (!extension_loaded("pdo_mysql") || !function_exists("pcntl_signal")) { fwrite(STDERR,"PDO MySQL and pcntl are required\\n"); exit(1); }')
    run('docker', 'network', 'create', network)
    run('docker', 'run', '-d', '--name', name, '--network', network, '--network-alias', 'mysql',
        '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0',
        '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci')
    for attempt in range(60):
        probe = subprocess.run(['docker', 'exec', name, 'mysqladmin', '-h127.0.0.1', 'ping', '--silent'], capture_output=True)
        if probe.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('MySQL startup timed out')
    run('docker', 'exec', name, 'mysql', '-h127.0.0.1', '-uroot', '-e',
        'CREATE DATABASE parity_fresh; CREATE DATABASE parity_custom; CREATE DATABASE parity_legacy;')
    install = (root / 'public/install/db/like.sql').read_text()
    repair = (root / 'public/upgrade/20261001_install_schema_parity.sql').read_text()
    assert repair == (root / 'upgrade/20261001_install_schema_parity.sql').read_text()
    sql('parity_fresh', install)
    sql('parity_custom', install.replace('`la_', '`qa_').replace("'la_", "'qa_"))
    legacy = install.split('-- IKJF3N: fresh-install schema and core permission parity.')[0]
    sql('parity_legacy', legacy)
    sql('parity_legacy', "INSERT INTO la_config (type,name,value) VALUES ('parity','sentinel','keep');")
    license_repair = (root / 'upgrade/20261008_site_license_copyright.sql').read_text()
    assert license_repair == (root / 'public/upgrade/20261008_site_license_copyright.sql').read_text()
    apps_before = sql('parity_legacy', 'SELECT COUNT(*) FROM la_app; SELECT COUNT(*) FROM la_tenant_app;')
    sql('parity_legacy', repair)
    sql('parity_legacy', repair)
    for prerequisite in ['20261005_user_tool_pins.sql', '20261007_picture_digital_human.sql']:
        sql('parity_legacy', (root / 'upgrade' / prerequisite).read_text())
    sql('parity_legacy', license_repair)
    sql('parity_legacy', license_repair)
    grant_repair = (root / 'upgrade/20261008_independent_update_rights.sql').read_text()
    assert grant_repair == (root / 'public/upgrade/20261008_independent_update_rights.sql').read_text()
    sql('parity_legacy', grant_repair)
    sql('parity_legacy', grant_repair)
    sql('parity_custom', grant_repair.replace('`la_', '`qa_'))
    worker_repair = (root / 'upgrade/20261011_short_drama_worker_schema.sql').read_text()
    assert worker_repair == (root / 'public/upgrade/20261011_short_drama_worker_schema.sql').read_text()
    # A partially upgraded poster table must retain active task ownership.
    sql('parity_legacy', (root / 'app/apps/aigc_short_drama/migrations/upgrade_20260919_canvas_video_poster.sql').read_text())
    sql('parity_legacy', "ALTER TABLE la_aigc_short_drama_canvas_poster_job ADD COLUMN job_kind varchar(20) NOT NULL DEFAULT 'poster';")
    sql('parity_legacy', "INSERT INTO la_aigc_short_drama_canvas_poster_job (status,lease_token,lease_expire_time,attempts,idempotency_key) VALUES ('running','preserve-owner',4294967295,2,'preserve-key');")
    before = sql('parity_legacy', 'SELECT id,status,lease_token,lease_expire_time,attempts,idempotency_key FROM la_aigc_short_drama_canvas_poster_job;')
    for database, prefix in [('parity_fresh', 'la_'), ('parity_custom', 'qa_'), ('parity_legacy', 'la_')]:
        content = worker_repair.replace('`la_', '`' + prefix).replace("'la_", "'" + prefix)
        sql(database, content)
        sql(database, content)
    assert before == sql('parity_legacy', 'SELECT id,status,lease_token,lease_expire_time,attempts,idempotency_key FROM la_aigc_short_drama_canvas_poster_job;')
    skill_tables = ['aigc_short_drama_script_task', 'aigc_short_drama_generation_task',
                    'aigc_short_drama_agent_run', 'aigc_short_drama_agent_step_log']
    skill_columns = ['skill_id', 'skill_version', 'skill_source', 'skill_snapshot_json']
    skill_repair = (root / 'upgrade/20261011_short_drama_skill_schema.sql').read_text()
    assert skill_repair == (root / 'public/upgrade/20261011_short_drama_skill_schema.sql').read_text()
    # Reproduce an installed legacy schema and a partially migrated custom prefix.
    for table in skill_tables:
        sql('parity_legacy', 'ALTER TABLE la_' + table + ' ' + ','.join('DROP COLUMN ' + column for column in skill_columns) + ';')
    sql('parity_custom', 'ALTER TABLE qa_aigc_short_drama_script_task DROP COLUMN skill_version;')
    sql('parity_legacy', "INSERT INTO la_aigc_short_drama_script_task (task_id,status,billing_status,user_charge_points,request_json) VALUES ('preserve-skill-task','running','charged',17.50,'{\"preserve\":true}');")
    preserved_skill_task = sql('parity_legacy', 'SELECT task_id,status,billing_status,user_charge_points,request_json FROM la_aigc_short_drama_script_task;')
    for database, prefix in [('parity_fresh', 'la_'), ('parity_custom', 'qa_'), ('parity_legacy', 'la_')]:
        migration = skill_repair.replace('`la_', '`' + prefix).replace("'la_", "'" + prefix)
        sql(database, migration)
        sql(database, migration)
        for table in skill_tables:
            for column in skill_columns:
                assert sql(database, f"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}{table}' AND COLUMN_NAME='{column}';").strip() == '1'
        if with_workers:
            print(run('docker', 'run', '--rm', *php_args(database, prefix), php_image,
                      'php', 'tests/short-drama-skill.integration.php'))
        print(database + ': sixteen Skill snapshot columns and twice-replayed repair passed')
    assert preserved_skill_task == sql('parity_legacy', 'SELECT task_id,status,billing_status,user_charge_points,request_json FROM la_aigc_short_drama_script_task;')
    assert apps_before == sql('parity_legacy', 'SELECT COUNT(*) FROM la_app; SELECT COUNT(*) FROM la_tenant_app;')
    assert sql('parity_legacy', "SELECT value FROM la_config WHERE type='parity' AND name='sentinel';").strip() == 'keep'
    # Check every shipped system-upgrade and app-install CREATE TABLE prerequisite.
    expected = set()
    sources = list((root / 'public/upgrade').glob('*.sql')) + list((root / 'app/apps').glob('*/migrations/*.sql'))
    for path in sources:
        expected.update(re.findall(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`(la_\w+)`', path.read_text(), re.I))
    schema_cases = [('parity_fresh', 'la_'), ('parity_custom', 'qa_'), ('parity_legacy', 'la_')]
    if '--fresh-only' in sys.argv:
        schema_cases = [item for item in schema_cases if item[0] != 'parity_legacy']
    for database, prefix in schema_cases:
        tables = set(sql(database, 'SHOW TABLES;').split())
        missing = {table.replace('la_', prefix, 1) for table in expected} - tables
        assert not missing, (database, sorted(missing))
        assert prefix + 'site_license_access_cache' in tables
        assert sql(database, f"SELECT COUNT(*) FROM {prefix}crontab WHERE command='site-license:refresh';").strip() == '1'
        for table, column in [('tenant_app_order', 'remark'), ('tenant_app_order', 'source_sn'),
            ('aigc_llm_model', 'platform_input_unit_price'), ('aigc_llm_model', 'platform_output_unit_price'),
            ('aigc_product_promo_video_config', 'market_enabled'), ('tenant_system_menu', 'delete_time'),
            ('aigc_short_drama_subject', 'three_view_image'),
            ('aigc_short_drama_canvas_poster_job', 'job_kind'),
            ('aigc_short_drama_canvas_poster_job', 'capture_time'),
            ('aigc_short_drama_canvas_poster_job', 'result_json'),
            *[('aigc_short_drama_episode_task', column) for column in
              ['queue_job_id', 'session_id', 'attempt_number', 'heartbeat_at', 'error_code', 'next_retry_at']]]:
            assert sql(database, f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}{table}' AND COLUMN_NAME='{column}';").strip() == '1'
        for table, permission in [('system_menu', 'ai_consumption/detail'), ('tenant_system_menu', 'ai_consumption/detail'),
            ('tenant_system_menu', 'decorate.template/export'), ('tenant_system_menu', 'decorate.template/import'),
            ('tenant_system_menu', 'decorate.data/sources')]:
            assert sql(database, f"SELECT COUNT(*) FROM {prefix}{table} WHERE perms='{permission}';").strip() == '1'
        print(database + ': all app table prerequisites, worker columns and five permissions passed (' + str(len(tables)) + ' tables)')
        if with_workers:
            smoke_workers(database, prefix)
    if with_workers:
        for database, prefix in [('parity_browser', 'la_'), ('parity_browser_custom', 'qa_')]:
            sql('parity_fresh', 'CREATE DATABASE ' + database + ';')
            print(run('docker', 'run', '--rm', *php_args(database, prefix), php_image,
                      'php', 'tests/install-worker-smoke.php', 'install'))
            smoke_workers(database, prefix)
            print(run('docker', 'run', '--rm', *php_args(database, prefix), php_image,
                      'php', 'tests/short-drama-skill.integration.php'))
    # Verify the new-tenant permission fragment on its actual per-tenant table form.
    sql('parity_fresh', 'CREATE TABLE la_tenant_system_menu_test LIKE la_tenant_system_menu; INSERT INTO la_tenant_system_menu_test SELECT * FROM la_tenant_system_menu; UPDATE la_tenant_system_menu_test SET tenant_id=7;')
    sql('parity_fresh', "DELETE FROM la_tenant_system_menu_test WHERE perms IN ('ai_consumption/detail','decorate.template/export','decorate.template/import','decorate.data/sources');")
    seed = (root / 'app/platformapi/db/tenantData.sql').read_text().split('/* IKJF3N: core action permissions for new tenants. */')[1]
    seed = seed.replace('{tenantSn}', 'test').replace('{tenantId}', '7')
    # Match TenantCreatService's semicolon/newline splitting and comment skip.
    for _ in range(2):
        for statement in ('/* IKJF3N: core action permissions for new tenants. */\n' + seed).split(';\n'):
            statement = statement.strip()
            if statement and not statement.startswith('--'):
                sql('parity_fresh', statement)
    assert sql('parity_fresh', "SELECT COUNT(*) FROM la_tenant_system_menu_test WHERE tenant_id=7 AND perms IN ('ai_consumption/detail','decorate.template/export','decorate.template/import','decorate.data/sources');").strip() == '4'
    print('new tenant: four permissions and repeat execution passed')
    if '--full-suite' in sys.argv:
        print(run('docker', 'run', '--rm', *php_args('parity_fresh', 'la_'), php_image,
                  'php', 'vendor/bin/phpunit', '-c', 'phpunit.xml.dist', '--do-not-cache-result'))
finally:
    for worker in workers:
        subprocess.run(['docker', 'rm', '-f', '-v', worker], capture_output=True)
    subprocess.run(['docker', 'rm', '-f', '-v', name], capture_output=True)
    subprocess.run(['docker', 'network', 'rm', network], capture_output=True)
