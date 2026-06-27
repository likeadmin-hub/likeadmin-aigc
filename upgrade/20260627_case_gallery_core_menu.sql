-- Ensure Case Gallery is a standalone core tenant menu and remove app-owned case menus.
UPDATE `la_tenant_system_menu`
SET `name` = '案例广场',
    `icon` = 'el-icon-PictureFilled',
    `sort` = 98,
    `perms` = 'case_gallery.case/lists',
    `paths` = 'case-gallery',
    `component` = 'case_gallery/index',
    `selected` = '/case-gallery',
    `app_code` = '',
    `source` = 'core',
    `source_menu_key` = 'core_tenant_case_gallery',
    `is_core` = 1,
    `update_time` = UNIX_TIMESTAMP()
WHERE `source_menu_key` = 'aigc_image_case';

DELETE FROM `la_tenant_system_menu`
WHERE `source_menu_key` IN ('aigc_video_case','aigc_digital_human_case');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT DISTINCT ta.`id`,0,'C','案例广场','el-icon-PictureFilled',98,'case_gallery.case/lists','case-gallery','case_gallery/index','/case-gallery','',0,1,0,'','core','core_tenant_case_gallery',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` ta
WHERE (ta.`delete_time` IS NULL OR ta.`delete_time` = 0)
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` m
    WHERE m.`tenant_id` = ta.`id`
      AND m.`source_menu_key` = 'core_tenant_case_gallery'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
JOIN (
    SELECT 'A' AS `type`,'应用选项' AS `name`,'' AS `icon`,0 AS `sort`,'case_gallery.case/apps' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_case_gallery_apps' AS `source_menu_key`,1 AS `is_core`
    UNION ALL SELECT 'A','详情','',0,'case_gallery.case/detail','','','','',0,0,0,'','core','core_tenant_case_gallery_detail',1
    UNION ALL SELECT 'A','保存','',0,'case_gallery.case/save','','','','',0,0,0,'','core','core_tenant_case_gallery_save',1
    UNION ALL SELECT 'A','任务加入','',0,'case_gallery.case/fromTask','','','','',0,0,0,'','core','core_tenant_case_gallery_from_task',1
    UNION ALL SELECT 'A','修改状态','',0,'case_gallery.case/status','','','','',0,0,0,'','core','core_tenant_case_gallery_status',1
    UNION ALL SELECT 'A','删除','',0,'case_gallery.case/delete','','','','',0,0,0,'','core','core_tenant_case_gallery_delete',1
) child
WHERE parent.`source_menu_key` = 'core_tenant_case_gallery'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = parent.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`, menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_menu` menu
JOIN `la_tenant_system_role` role ON role.`id` = rm.`role_id`
WHERE menu.`source_menu_key` IN (
    'core_tenant_case_gallery',
    'core_tenant_case_gallery_apps',
    'core_tenant_case_gallery_detail',
    'core_tenant_case_gallery_save',
    'core_tenant_case_gallery_from_task',
    'core_tenant_case_gallery_status',
    'core_tenant_case_gallery_delete'
)
  AND role.`tenant_id` = menu.`tenant_id`
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('case_gallery','case_gallery.case/lists','GET','case_gallery:case:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/apps','GET','case_gallery:case:apps','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/detail','GET','case_gallery:case:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/save','POST','case_gallery:case:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/fromTask','POST','case_gallery:case:from_task','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/status','POST','case_gallery:case:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/delete','POST','case_gallery:case:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('case_gallery','case_gallery.case/lists','GET','case_gallery:case:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`scene`=VALUES(`scene`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);
