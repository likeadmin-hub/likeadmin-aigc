UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` system_default
  ON system_default.`tenant_id` = child.`tenant_id`
 AND system_default.`source_menu_key` = 'core_tenant_system_default'
 AND system_default.`source` <> 'tenant'
SET child.`pid` = system_default.`id`,
    child.`type` = 'C',
    child.`name` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN '案例广场'
        WHEN 'core_tenant_pc_notice' THEN '消息公告'
        ELSE child.`name`
    END,
    child.`icon` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN 'el-icon-PictureFilled'
        WHEN 'core_tenant_pc_notice' THEN 'el-icon-Bell'
        ELSE child.`icon`
    END,
    child.`perms` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN 'case_gallery.case/lists'
        WHEN 'core_tenant_pc_notice' THEN 'notice.pc_notice/lists'
        ELSE child.`perms`
    END,
    child.`paths` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN 'case-gallery'
        WHEN 'core_tenant_pc_notice' THEN 'notice'
        ELSE child.`paths`
    END,
    child.`component` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN 'case_gallery/index'
        WHEN 'core_tenant_pc_notice' THEN 'message/pc_notice/index'
        ELSE child.`component`
    END,
    child.`selected` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN '/case-gallery'
        WHEN 'core_tenant_pc_notice' THEN ''
        ELSE child.`selected`
    END,
    child.`app_code` = 'system_default',
    child.`source` = 'core',
    child.`is_core` = 1,
    child.`is_show` = 1,
    child.`is_disable` = 0,
    child.`sort` = CASE child.`source_menu_key`
        WHEN 'core_tenant_case_gallery' THEN 98
        WHEN 'core_tenant_pc_notice' THEN 97
        ELSE child.`sort`
    END,
    child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source` <> 'tenant'
  AND child.`source_menu_key` IN ('core_tenant_case_gallery','core_tenant_pc_notice');

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
 AND parent.`source` <> 'tenant'
 AND (
      (parent.`source_menu_key` = 'core_tenant_case_gallery'
       AND child.`source_menu_key` IN (
         'core_tenant_case_gallery_apps',
         'core_tenant_case_gallery_detail',
         'core_tenant_case_gallery_save',
         'core_tenant_case_gallery_from_task',
         'core_tenant_case_gallery_status',
         'core_tenant_case_gallery_delete'
       ))
      OR
      (parent.`source_menu_key` = 'core_tenant_pc_notice'
       AND child.`source_menu_key` IN (
         'core_tenant_pc_notice_detail',
         'core_tenant_pc_notice_add',
         'core_tenant_pc_notice_edit',
         'core_tenant_pc_notice_delete',
         'core_tenant_pc_notice_status'
       ))
 )
SET child.`pid` = parent.`id`,
    child.`app_code` = 'system_default',
    child.`source` = 'core',
    child.`is_core` = 1,
    child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source` <> 'tenant';

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`, system_default.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
JOIN `la_tenant_system_menu` system_default
  ON system_default.`tenant_id` = menu.`tenant_id`
 AND system_default.`source_menu_key` = 'core_tenant_system_default'
 AND system_default.`source` <> 'tenant'
WHERE menu.`source` <> 'tenant'
  AND menu.`source_menu_key` IN (
    'core_tenant_case_gallery',
    'core_tenant_case_gallery_apps',
    'core_tenant_case_gallery_detail',
    'core_tenant_case_gallery_save',
    'core_tenant_case_gallery_from_task',
    'core_tenant_case_gallery_status',
    'core_tenant_case_gallery_delete',
    'core_tenant_pc_notice',
    'core_tenant_pc_notice_detail',
    'core_tenant_pc_notice_add',
    'core_tenant_pc_notice_edit',
    'core_tenant_pc_notice_delete',
    'core_tenant_pc_notice_status'
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`, menu.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` system_default ON system_default.`id` = role_menu.`menu_id`
JOIN `la_tenant_system_menu` menu
  ON menu.`tenant_id` = system_default.`tenant_id`
 AND menu.`source` <> 'tenant'
WHERE system_default.`source_menu_key` = 'core_tenant_system_default'
  AND system_default.`source` <> 'tenant'
  AND menu.`source_menu_key` IN (
    'core_tenant_case_gallery',
    'core_tenant_case_gallery_apps',
    'core_tenant_case_gallery_detail',
    'core_tenant_case_gallery_save',
    'core_tenant_case_gallery_from_task',
    'core_tenant_case_gallery_status',
    'core_tenant_case_gallery_delete',
    'core_tenant_pc_notice',
    'core_tenant_pc_notice_detail',
    'core_tenant_pc_notice_add',
    'core_tenant_pc_notice_edit',
    'core_tenant_pc_notice_delete',
    'core_tenant_pc_notice_status'
  );
