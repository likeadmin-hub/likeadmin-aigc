#!/usr/bin/env python3
import pathlib,subprocess,uuid
root=pathlib.Path(__file__).resolve().parents[1];key=uuid.uuid4().hex[:8];created=[]
def run(args,data=None):
 p=subprocess.run(args,input=data,text=True,capture_output=True);p.check_returncode();return p.stdout.strip()
def sql(db,q):return run(['docker','exec','-i','likeadmin-oem-db','mysql','-uroot','--default-character-set=utf8mb4','-N',db],q)
def migrate(db,prefix,content):return run(['docker','run','--rm','-i','--network','likeadmin-oem-dev','-v',str(root)+':/repo:ro','--tmpfs','/repo/runtime','-w','/repo','-e','OEM_DISPOSABLE=1','-e','PHP_DATABASE_HOSTNAME=oem-mysql','-e','PHP_DATABASE_DATABASE='+db,'-e','PHP_DATABASE_PREFIX='+prefix,'-e','PHP_DATABASE_USERNAME=root','-e','PHP_DATABASE_PASSWORD=','-e','PHP_CACHE_DRIVER=file','likeadmin-install-worker-test:local','php','tests/oem-schema-migrate.php'],content)
try:
 migration=(root/'upgrade/20261014_package_promotion.sql').read_text();assert migration==(root/'public/upgrade/20261014_package_promotion.sql').read_text()
 virtual=(root/'upgrade/20261014_virtual_payment.sql').read_text();assert virtual==(root/'public/upgrade/20261014_virtual_payment.sql').read_text();orders=(root/'upgrade/20261014_virtual_payment_orders.sql').read_text();assert orders==(root/'public/upgrade/20261014_virtual_payment_orders.sql').read_text();recovery=(root/'upgrade/20261014_virtual_payment_recovery.sql').read_text();assert recovery==(root/'public/upgrade/20261014_virtual_payment_recovery.sql').read_text();migration+='\n'+virtual+'\n'+orders+'\n'+recovery
 platforms=(root/'upgrade/20261014_virtual_payment_platforms.sql').read_text();assert platforms==(root/'public/upgrade/20261014_virtual_payment_platforms.sql').read_text();migration+='\n'+platforms
 old=run(['git','-C',str(root),'show','24301c49d:public/install/db/like.sql']);install=(root/'public/install/db/like.sql').read_text()
 for mode,prefix in [('fresh','la_'),('upgrade','la_'),('prefix','qa_')]:
  db='oem_promotion_schema_'+mode+'_'+key;created.append(db);run(['docker','exec','likeadmin-oem-db','mysql','-uroot','-e','CREATE DATABASE '+db]);sql(db,(install if mode=='fresh' else old).replace('la_',prefix))
  migrate(db,prefix,migration);sql(db,f"INSERT INTO {prefix}tenant_package_promotion (tenant_id,biz_type,package_id,enabled,discount_prices) VALUES(77,'membership',44,1,'{{\"package\":990}}');")
  sql(db,f"INSERT INTO {prefix}virtual_pay_config (tenant_id,app_id,env,enabled) VALUES(77,'wx-schema',0,1);");assert sql(db,f"SELECT enabled_platforms FROM {prefix}virtual_pay_config WHERE tenant_id=77")=='ios,android';sql(db,f"UPDATE {prefix}virtual_pay_config SET enabled_platforms='ios' WHERE tenant_id=77")
  migrate(db,prefix,migration)
  assert sql(db,f"SELECT enabled_platforms FROM {prefix}virtual_pay_config WHERE tenant_id=77")=='ios'
  for table in ['tenant_package_promotion','tenant_package_discount_claim','tenant_package_price_snapshot','virtual_pay_config','virtual_pay_config_version','virtual_pay_product','virtual_pay_session','virtual_pay_intent','virtual_pay_receipt','virtual_pay_event','virtual_pay_work']:assert sql(db,f"SHOW TABLES LIKE '{prefix}{table}'")==prefix+table
  assert sql(db,f"SELECT enabled FROM {prefix}tenant_package_promotion WHERE tenant_id=77 AND package_id=44")=='1'
  assert sql(db,f"SELECT COUNT(*) FROM {prefix}tenant_system_menu WHERE tenant_id=0 AND perms='finance.virtual_pay/detail'")=='1'
  assert sql(db,f"SELECT COUNT(*) FROM {prefix}tenant_system_menu WHERE tenant_id=0 AND perms='finance.promotion/save'")=='1'
  assert sql(db,f"SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{prefix}tenant_package_promotion' AND COLUMN_NAME='enabled'")=='0'
  assert sql(db,f"SELECT COUNT(*) FROM {prefix}tenant_system_menu WHERE tenant_id=0 AND perms='finance.virtual_pay/save'")=='1'
  assert sql(db,f"SELECT COUNT(*) FROM {prefix}tenant_system_menu WHERE tenant_id=0 AND perms='finance.promotion/detail'")=='1'
  assert '{tenantSn}' in (root/'app/platformapi/db/tenantData.sql').read_text() and 'finance.virtual_pay/detail' in (root/'app/platformapi/db/tenantData.sql').read_text()
  print('PASS promotion '+mode+': actual migration executor/repeat/disabled default/new tenant menu')
finally:
 for db in created:subprocess.run(['docker','exec','likeadmin-oem-db','mysql','-uroot','-e','DROP DATABASE IF EXISTS '+db],capture_output=True)
