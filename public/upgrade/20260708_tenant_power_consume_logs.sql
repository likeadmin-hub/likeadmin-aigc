-- 租户端「算力商城 > 消耗日志」菜单与权限

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,0,'M','算力商城','el-icon-Goods',70,'','power-mall','','','',0,1,0,'','core','core_tenant_power_mall',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `source_menu_key` = 'core_tenant_power_mall'
);

SET @core_tenant_power_template_id := (
  SELECT `id` FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `source_menu_key` = 'core_tenant_power_mall'
  ORDER BY `id` DESC
  LIMIT 1
);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@core_tenant_power_template_id,'C','消耗日志','el-icon-Notebook',80,'power.mall/consumeLogs','consume-logs','power_mall/consume_logs','','',0,1,0,'','core','core_tenant_power_mall_consume_logs',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @core_tenant_power_template_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu`
    WHERE `tenant_id` = 0 AND `source_menu_key` = 'core_tenant_power_mall_consume_logs'
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
  AND parent.`source_menu_key` = 'core_tenant_power_mall'
SET child.`pid` = parent.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`tenant_id` = 0
  AND child.`source_menu_key` = 'core_tenant_power_mall_consume_logs'
  AND child.`pid` <> parent.`id`;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT consume_logs.`tenant_id`,consume_logs.`id`,'A','消耗详情','',0,'power.mall/consumeLogDetail','','','','',0,0,0,'','core','core_tenant_power_mall_consume_log_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` consume_logs
WHERE consume_logs.`tenant_id` = 0
  AND consume_logs.`source_menu_key` = 'core_tenant_power_mall_consume_logs'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = consume_logs.`tenant_id`
      AND exists_menu.`source_menu_key` = 'core_tenant_power_mall_consume_log_detail'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT ta.`id`,0,'M','算力商城','el-icon-Goods',70,'','power-mall','','','',0,1,0,'','core','core_tenant_power_mall',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` ta
WHERE (ta.`delete_time` IS NULL OR ta.`delete_time` = 0)
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` m
    WHERE m.`tenant_id` = ta.`id`
      AND m.`source_menu_key` = 'core_tenant_power_mall'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','消耗日志','el-icon-Notebook',80,'power.mall/consumeLogs','consume-logs','power_mall/consume_logs','','',0,1,0,'','core','core_tenant_power_mall_consume_logs',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key` = 'core_tenant_power_mall'
  AND parent.`tenant_id` > 0
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = parent.`tenant_id`
      AND exists_menu.`source_menu_key` = 'core_tenant_power_mall_consume_logs'
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
  AND parent.`source_menu_key` = 'core_tenant_power_mall'
SET child.`pid` = parent.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` = 'core_tenant_power_mall_consume_logs'
  AND child.`pid` <> parent.`id`;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT consume_logs.`tenant_id`,consume_logs.`id`,'A','消耗详情','',0,'power.mall/consumeLogDetail','','','','',0,0,0,'','core','core_tenant_power_mall_consume_log_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` consume_logs
WHERE consume_logs.`tenant_id` > 0
  AND consume_logs.`source_menu_key` = 'core_tenant_power_mall_consume_logs'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = consume_logs.`tenant_id`
      AND exists_menu.`source_menu_key` = 'core_tenant_power_mall_consume_log_detail'
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` consume_logs
  ON consume_logs.`tenant_id` = child.`tenant_id`
  AND consume_logs.`source_menu_key` = 'core_tenant_power_mall_consume_logs'
SET child.`pid` = consume_logs.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` = 'core_tenant_power_mall_consume_log_detail'
  AND child.`pid` <> consume_logs.`id`;

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`, menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` role ON role.`id` = rm.`role_id`
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE menu.`source_menu_key` IN (
    'core_tenant_power_mall',
    'core_tenant_power_mall_consume_logs',
    'core_tenant_power_mall_consume_log_detail'
  )
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
