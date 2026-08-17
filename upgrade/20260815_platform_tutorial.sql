DELETE role_menu
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id`=role_menu.`menu_id`
WHERE menu.`source_menu_key` IN ('core_tenant_tutorial','core_tenant_tutorial_save');

DELETE FROM `la_tenant_system_menu`
WHERE `source_menu_key` IN ('core_tenant_tutorial','core_tenant_tutorial_save');

DELETE FROM `la_tenant_config`
WHERE `type`='tutorial';

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT website.`id`,'C','新手教程','el-icon-Guide',2,'setting.web.web_setting/getTutorial','tutorial','setting/website/information','','',0,1,0,'','core','core_platform_tutorial',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` website
WHERE website.`type`='M'
  AND website.`paths`='website'
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` existing
    WHERE existing.`source_menu_key`='core_platform_tutorial'
  );

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT tutorial.`id`,'A','保存','',0,'setting.web.web_setting/setTutorial','','','','',0,0,0,'','core','core_platform_tutorial_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` tutorial
WHERE tutorial.`source_menu_key`='core_platform_tutorial'
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` existing
    WHERE existing.`source_menu_key`='core_platform_tutorial_save'
  );

UPDATE `la_system_menu` tutorial
JOIN `la_system_menu` website ON website.`type`='M' AND website.`paths`='website'
SET tutorial.`pid`=website.`id`,
    tutorial.`name`='新手教程',
    tutorial.`sort`=2,
    tutorial.`perms`='setting.web.web_setting/getTutorial',
    tutorial.`paths`='tutorial',
    tutorial.`component`='setting/website/information',
    tutorial.`is_show`=1,
    tutorial.`is_disable`=0,
    tutorial.`update_time`=UNIX_TIMESTAMP()
WHERE tutorial.`source_menu_key`='core_platform_tutorial';

UPDATE `la_system_menu` save_menu
JOIN `la_system_menu` tutorial ON tutorial.`source_menu_key`='core_platform_tutorial'
SET save_menu.`pid`=tutorial.`id`,
    save_menu.`perms`='setting.web.web_setting/setTutorial',
    save_menu.`is_show`=0,
    save_menu.`is_disable`=0,
    save_menu.`update_time`=UNIX_TIMESTAMP()
WHERE save_menu.`source_menu_key`='core_platform_tutorial_save';

INSERT IGNORE INTO `la_system_role_menu` (`role_id`,`menu_id`)
SELECT role_menu.`role_id`,tutorial.`id`
FROM `la_system_role_menu` role_menu
JOIN `la_system_menu` website ON website.`id`=role_menu.`menu_id`
JOIN `la_system_menu` tutorial ON tutorial.`source_menu_key`='core_platform_tutorial'
WHERE website.`type`='M' AND website.`paths`='website';

INSERT IGNORE INTO `la_system_role_menu` (`role_id`,`menu_id`)
SELECT role_menu.`role_id`,save_menu.`id`
FROM `la_system_role_menu` role_menu
JOIN `la_system_menu` tutorial
  ON tutorial.`id`=role_menu.`menu_id`
 AND tutorial.`source_menu_key`='core_platform_tutorial'
JOIN `la_system_menu` save_menu ON save_menu.`source_menu_key`='core_platform_tutorial_save';
