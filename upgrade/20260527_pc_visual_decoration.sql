UPDATE `la_tenant_system_menu`
SET `name`='模板管理',
    `pid`=96,
    `sort`=100,
    `perms`='decorate.template/lists',
    `paths`='template',
    `component`='decoration/template/index',
    `is_show`=1,
    `update_time`=UNIX_TIMESTAMP()
WHERE `id`=97;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,97,'A','导出模板','',0,'decorate.template/export','','','','',0,1,0,'','core','core_tenant_decorate_template_export',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `perms`='decorate.template/export');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,97,'A','导入模板','',0,'decorate.template/import','','','','',0,1,0,'','core','core_tenant_decorate_template_import',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `perms`='decorate.template/import');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,97,'A','数据源','',0,'decorate.data/sources','','','','',0,1,0,'','core','core_tenant_decorate_data_sources',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `perms`='decorate.data/sources');
