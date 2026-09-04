-- Add the tenant user password reset permission and grant it to existing tenant roles.

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`, parent.`id`, 'A', '修改密码', '', 1, 'user.user/resetPassword', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_consumer_reset_password', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`type` = 'C'
  AND parent.`paths` = 'lists'
  AND parent.`component` = 'consumer/lists/detail'
  AND NOT EXISTS (
    SELECT 1
    FROM `la_tenant_system_menu` existing_menu
    WHERE existing_menu.`tenant_id` = parent.`tenant_id`
      AND (existing_menu.`source_menu_key` = 'core_tenant_consumer_reset_password'
        OR existing_menu.`perms` = 'user.user/resetPassword')
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT role.`id`, menu.`id`
FROM `la_tenant_system_role` role
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE menu.`source_menu_key` = 'core_tenant_consumer_reset_password'
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
