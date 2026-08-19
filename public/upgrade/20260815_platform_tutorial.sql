DELETE role_menu
FROM `la_system_role_menu` role_menu
JOIN `la_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE menu.`source_menu_key` IN ('core_platform_tutorial','core_platform_tutorial_save');

DELETE FROM `la_system_menu`
WHERE `source_menu_key` IN ('core_platform_tutorial','core_platform_tutorial_save');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT setting.`tenant_id`,setting.`id`,'C','新手教程','el-icon-Guide',95,'setting.web.web_setting/getTutorial','tutorial','setting/website/information','','',0,1,0,'','core','core_tenant_tutorial',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` setting
WHERE setting.`type`='M'
  AND setting.`pid`=0
  AND setting.`paths`='setting'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` existing
    WHERE existing.`tenant_id`=setting.`tenant_id`
      AND existing.`source_menu_key`='core_tenant_tutorial'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT tutorial.`tenant_id`,tutorial.`id`,'A','保存','',0,'setting.web.web_setting/setTutorial','','','','',0,0,0,'','core','core_tenant_tutorial_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` tutorial
WHERE tutorial.`source_menu_key`='core_tenant_tutorial'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` existing
    WHERE existing.`tenant_id`=tutorial.`tenant_id`
      AND existing.`source_menu_key`='core_tenant_tutorial_save'
  );

UPDATE `la_tenant_system_menu` tutorial
JOIN `la_tenant_system_menu` setting
  ON setting.`tenant_id`=tutorial.`tenant_id`
 AND setting.`type`='M'
 AND setting.`pid`=0
 AND setting.`paths`='setting'
SET tutorial.`pid`=setting.`id`,
    tutorial.`name`='新手教程',
    tutorial.`icon`='el-icon-Guide',
    tutorial.`sort`=95,
    tutorial.`perms`='setting.web.web_setting/getTutorial',
    tutorial.`paths`='tutorial',
    tutorial.`component`='setting/website/information',
    tutorial.`app_code`='',
    tutorial.`source`='core',
    tutorial.`is_core`=1,
    tutorial.`is_show`=1,
    tutorial.`is_disable`=0,
    tutorial.`update_time`=UNIX_TIMESTAMP()
WHERE tutorial.`source_menu_key`='core_tenant_tutorial';

UPDATE `la_tenant_system_menu` save_menu
JOIN `la_tenant_system_menu` tutorial
  ON tutorial.`tenant_id`=save_menu.`tenant_id`
 AND tutorial.`source_menu_key`='core_tenant_tutorial'
SET save_menu.`pid`=tutorial.`id`,
    save_menu.`name`='保存',
    save_menu.`perms`='setting.web.web_setting/setTutorial',
    save_menu.`app_code`='',
    save_menu.`source`='core',
    save_menu.`is_core`=1,
    save_menu.`is_show`=0,
    save_menu.`is_disable`=0,
    save_menu.`update_time`=UNIX_TIMESTAMP()
WHERE save_menu.`source_menu_key`='core_tenant_tutorial_save';

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT role_menu.`role_id`,tutorial.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` setting ON setting.`id`=role_menu.`menu_id`
JOIN `la_tenant_system_menu` tutorial
  ON tutorial.`tenant_id`=setting.`tenant_id`
 AND tutorial.`source_menu_key`='core_tenant_tutorial'
WHERE setting.`type`='M'
  AND setting.`pid`=0
  AND setting.`paths`='setting';

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT role_menu.`role_id`,save_menu.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` tutorial
  ON tutorial.`id`=role_menu.`menu_id`
 AND tutorial.`source_menu_key`='core_tenant_tutorial'
JOIN `la_tenant_system_menu` save_menu
  ON save_menu.`tenant_id`=tutorial.`tenant_id`
 AND save_menu.`source_menu_key`='core_tenant_tutorial_save';
