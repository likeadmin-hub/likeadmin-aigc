#!/usr/bin/env python3
"""Validate fresh/legacy/custom-prefix installs in a disposable MySQL 8 container."""
import pathlib
import re
import subprocess
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[1]
name = 'ikjf3n-parity-' + uuid.uuid4().hex[:10]

def run(*args, data=None):
    result = subprocess.run(args, input=data, text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or result.stdout.strip())
    return result.stdout

def sql(database, text):
    return run('docker', 'exec', '-i', name, 'mysql', '-h127.0.0.1', '--default-character-set=utf8mb4', '-uroot', '-N', database, data=text)

try:
    run('docker', 'run', '-d', '--name', name, '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0',
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
    assert apps_before == sql('parity_legacy', 'SELECT COUNT(*) FROM la_app; SELECT COUNT(*) FROM la_tenant_app;')
    assert sql('parity_legacy', "SELECT value FROM la_config WHERE type='parity' AND name='sentinel';").strip() == 'keep'
    # Check every shipped system-upgrade and app-install CREATE TABLE prerequisite.
    expected = set()
    sources = list((root / 'public/upgrade').glob('*.sql')) + list((root / 'app/apps').glob('*/migrations/install.sql'))
    for path in sources:
        expected.update(re.findall(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`(la_\w+)`', path.read_text(), re.I))
    for database, prefix in [('parity_fresh', 'la_'), ('parity_custom', 'qa_'), ('parity_legacy', 'la_')]:
        tables = set(sql(database, 'SHOW TABLES;').split())
        missing = {table.replace('la_', prefix, 1) for table in expected} - tables
        assert not missing, (database, sorted(missing))
        assert prefix + 'site_license_access_cache' in tables
        assert sql(database, f"SELECT COUNT(*) FROM {prefix}crontab WHERE command='site-license:refresh';").strip() == '1'
        for table, column in [('tenant_app_order', 'remark'), ('tenant_app_order', 'source_sn'),
            ('aigc_llm_model', 'platform_input_unit_price'), ('aigc_llm_model', 'platform_output_unit_price'),
            ('aigc_product_promo_video_config', 'market_enabled'), ('tenant_system_menu', 'delete_time'),
            ('aigc_short_drama_subject', 'three_view_image')]:
            assert sql(database, f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}{table}' AND COLUMN_NAME='{column}';").strip() == '1'
        for table, permission in [('system_menu', 'ai_consumption/detail'), ('tenant_system_menu', 'ai_consumption/detail'),
            ('tenant_system_menu', 'decorate.template/export'), ('tenant_system_menu', 'decorate.template/import'),
            ('tenant_system_menu', 'decorate.data/sources')]:
            assert sql(database, f"SELECT COUNT(*) FROM {prefix}{table} WHERE perms='{permission}';").strip() == '1'
        print(database + ': table prerequisites, seven columns and five permissions passed (' + str(len(tables)) + ' tables)')
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
finally:
    subprocess.run(['docker', 'rm', '-f', '-v', name], capture_output=True)
