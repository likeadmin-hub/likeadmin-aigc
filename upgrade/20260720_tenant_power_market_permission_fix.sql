INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('system_default','power.market/models','GET','power_market:models','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('system_default','power.market/apps','GET','power_market:apps','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('system_default','power.market/detail','GET','power_market:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('system_default','power.market/appDetail','GET','power_market:app_detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('system_default','power.market/savePrices','POST','power_market:save_prices','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('system_default','power.market/batchShelf','POST','power_market:batch_shelf','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('system_default','power.market/saveDisplay','POST','power_market:save_display','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`permission_key`=VALUES(`permission_key`),
`need_login`=VALUES(`need_login`),
`need_role_permission`=VALUES(`need_role_permission`),
`status`=VALUES(`status`),
`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT market.`tenant_id`,market.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` market
JOIN (
  SELECT 'A' AS `type`,'批量上架' AS `name`,'' AS `icon`,0 AS `sort`,'power.market/batchShelf' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_market_batch_shelf' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','保存展示','',0,'power.market/saveDisplay','','','','',0,0,0,'','core','core_tenant_power_market_save_display',1
) child
WHERE market.`source_menu_key` = 'core_tenant_power_market'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = market.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`,menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` role ON role.`id` = rm.`role_id`
JOIN `la_tenant_system_menu` parent ON parent.`tenant_id` = role.`tenant_id` AND parent.`source_menu_key` = 'core_tenant_power_market'
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE rm.`menu_id` = parent.`id`
  AND menu.`source_menu_key` IN (
    'core_tenant_power_market_batch_shelf',
    'core_tenant_power_market_save_display'
  )
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);

DELETE role_menu
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE menu.`app_code`='aigc_fitting'
  AND menu.`source`='app'
  AND menu.`source_menu_key`='aigc_fitting_garment';

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_fitting'
  AND `source`='app'
  AND `source_menu_key`='aigc_fitting_garment';
