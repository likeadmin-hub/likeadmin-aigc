#!/usr/bin/env python3
"""Real MySQL schema contract. Every resource is disposable and isolated."""
import pathlib
import subprocess
import time
import uuid

root = pathlib.Path(__file__).resolve().parents[1]
name = 'oem-schema-' + uuid.uuid4().hex[:10]

def run(*args, data=None):
    p = subprocess.run(args, input=data, text=True, capture_output=True)
    if p.returncode:
        raise RuntimeError(p.stderr or p.stdout)
    return p.stdout.strip()

def sql(db, query):
    return run('docker', 'exec', '-i', name, 'mysql', '-uroot', '-N', '--default-character-set=utf8mb4', db, data=query)

try:
    run('docker', 'run', '-d', '--name', name, '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0')
    for _ in range(60):
        # The image's temporary initialization server accepts socket pings, then exits.
        # TCP becomes ready only after the final MySQL instance has started.
        if subprocess.run(['docker', 'exec', name, 'mysqladmin', '-h127.0.0.1', 'ping', '--silent'], capture_output=True).returncode == 0:
            break
        time.sleep(1)
    run('docker', 'exec', name, 'mysql', '-uroot', '-e', 'CREATE DATABASE oem_fresh; CREATE DATABASE oem_legacy; CREATE DATABASE oem_custom;')
    install = (root / 'public/install/db/like.sql').read_text()
    sql('oem_fresh', install)
    codes = sql('oem_fresh', "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package' AND COLUMN_NAME='package_code';")
    assert codes == '1', 'OEM stable package codes are missing from a fresh installation'
    migration = (root / 'upgrade/20261013_oem_self_service_v2.sql').read_text()
    assert migration == (root / 'public/upgrade/20261013_oem_self_service_v2.sql').read_text()
    operations = (root / 'upgrade/20261013_oem_self_service_v2_operations.sql').read_text()
    assert operations == (root / 'public/upgrade/20261013_oem_self_service_v2_operations.sql').read_text()
    migration += '\n' + operations
    maintenance = (root / 'upgrade/20261014_oem_maintenance.sql').read_text()
    assert maintenance == (root / 'public/upgrade/20261014_oem_maintenance.sql').read_text()
    migration += '\n' + maintenance
    recovery=(root/'upgrade/20261014_oem_refund_recovery.sql').read_text()
    assert recovery==(root/'public/upgrade/20261014_oem_refund_recovery.sql').read_text()
    migration+='\n'+recovery
    sql('oem_legacy', install.split('-- OEM_V2:')[0])
    sql('oem_legacy', "INSERT INTO la_tenant_package(name,duration_days,sale_price,quota_price) VALUES('Existing',77,123.45,88.88);")
    sql('oem_custom', install.replace('`la_', '`qa_').replace("'la_", "'qa_"))
    for db, prefix in [('oem_fresh', 'la_'), ('oem_legacy', 'la_'), ('oem_custom', 'qa_')]:
        seed = migration.replace('`la_', '`' + prefix).replace("'la_", "'" + prefix)
        sql(db, seed)
        sql(db, seed)
        assert sql(db,f"SELECT COUNT(*) FROM {prefix}system_menu WHERE perms='brand.order/refunds';")=='1'
        defaults = sql(db, f"SELECT name,duration_days,status,prices_configured FROM {prefix}tenant_package WHERE package_code IS NOT NULL ORDER BY package_code;")
        assert defaults == '基础版\t365\t0\t0\n企业版\t365\t0\t0\n专业版\t365\t0\t0', defaults
        sql(db, f"UPDATE {prefix}tenant_package SET sale_price=299.99,quota_price=199.99 WHERE package_code='oem_basic';")
        sql(db, seed)
        assert sql(db, f"SELECT sale_price,quota_price FROM {prefix}tenant_package WHERE package_code='oem_basic';") == '299.99\t199.99'
        for table in ['tenant_brand_event', 'tenant_brand_payment', 'tenant_brand_payment_intent', 'tenant_brand_refund', 'tenant_brand_job', 'tenant_brand_domain']:
            assert sql(db, f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}{table}';") == '1', table
        assert sql(db, f"SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}tenant_brand_order' AND COLUMN_NAME='flow_version';") == '1'
        assert sql(db, f"SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}tenant' AND COLUMN_NAME='oem_sales_enabled';") == '0'
        assert sql(db, f"SELECT COUNT(*) FROM {prefix}dev_crontab WHERE command='brand:maintain' AND status=1;") == '1', 'OEM maintenance scheduled once'
        for perm in ['brand.order/lists','brand.order/confirm','brand.order/saveSeller','brand.order/resolveRefund']:
            assert sql(db, f"SELECT COUNT(*) FROM {prefix}system_menu WHERE perms='{perm}';") == '1', 'platform permission ' + perm
        for perm in ['brand.order/detail','brand.order/review','brand.order/submit','brand.order/refundReview','brand.quota/detail','brand.quota/purchaseForOrder']:
            assert sql(db, f"SELECT COUNT(*) FROM {prefix}tenant_system_menu WHERE tenant_id=0 AND perms='{perm}';") == '1', 'tenant permission ' + perm
        print('PASS ' + db + ': defaults, snapshots/jobs/payment schema and repeat migration')
    assert sql('oem_legacy', "SELECT duration_days,sale_price,quota_price FROM la_tenant_package WHERE name='Existing';") == '77\t123.45\t88.88'
finally:
    subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
