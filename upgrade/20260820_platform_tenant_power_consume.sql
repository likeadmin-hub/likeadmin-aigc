SET @tenant_root_id := COALESCE(
  (SELECT `id` FROM `la_system_menu` WHERE `paths`='tenant' AND `pid`=0 LIMIT 1),
  117
);

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @tenant_root_id,'C','算力消耗明细','el-icon-DataLine',92,'tenant.power_consume/lists','power-consume','tenant/power_consume/index','','',0,1,0,'','core','core_tenant_power_consume_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_tenant_power_consume_platform'
);

UPDATE `la_system_menu`
SET `pid`=@tenant_root_id,
    `type`='C',
    `name`='算力消耗明细',
    `icon`='el-icon-DataLine',
    `sort`=92,
    `perms`='tenant.power_consume/lists',
    `paths`='power-consume',
    `component`='tenant/power_consume/index',
    `is_show`=1,
    `is_disable`=0,
    `source`='core',
    `is_core`=1,
    `update_time`=UNIX_TIMESTAMP()
WHERE `source_menu_key`='core_tenant_power_consume_platform';
