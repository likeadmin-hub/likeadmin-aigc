DELETE FROM `la_app_api`
WHERE `app_code`='aigc_hairstyle'
  AND `api_path` IN ('app.aigc_hairstyle.price/detail','app.aigc_hairstyle.price/setup');

UPDATE `la_tenant_system_menu`
SET `sort`=20,`is_show`=1,`is_disable`=0,`perms`='app.aigc_hairstyle.config/detail',`paths`='config',`component`='apps/aigc_hairstyle/config',`update_time`=UNIX_TIMESTAMP()
WHERE `app_code`='aigc_hairstyle'
  AND `source`='app'
  AND `source_menu_key`='aigc_hairstyle_config';

UPDATE `la_tenant_system_menu`
SET `sort`=10,`is_show`=1,`is_disable`=0,`perms`='app.aigc_hairstyle.task/lists',`paths`='task',`component`='apps/aigc_hairstyle/task',`update_time`=UNIX_TIMESTAMP()
WHERE `app_code`='aigc_hairstyle'
  AND `source`='app'
  AND `source_menu_key`='aigc_hairstyle_task';

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_hairstyle'
  AND `source`='app'
  AND `source_menu_key`='aigc_hairstyle_price';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',20,'app.aigc_hairstyle.config/detail','config','apps/aigc_hairstyle/config','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_hairstyle'
  AND root.`source`='app'
  AND root.`source_menu_key`='aigc_hairstyle'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` child
    WHERE child.`tenant_id`=root.`tenant_id`
      AND child.`app_code`='aigc_hairstyle'
      AND child.`source`='app'
      AND child.`source_menu_key`='aigc_hairstyle_config'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_hairstyle.task/lists','task','apps/aigc_hairstyle/task','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_hairstyle'
  AND root.`source`='app'
  AND root.`source_menu_key`='aigc_hairstyle'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` child
    WHERE child.`tenant_id`=root.`tenant_id`
      AND child.`app_code`='aigc_hairstyle'
      AND child.`source`='app'
      AND child.`source_menu_key`='aigc_hairstyle_task'
  );
