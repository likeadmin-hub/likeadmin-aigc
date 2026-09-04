-- Add tenant user membership assignment permissions and grant them to existing roles.
-- The operation replaces the user's current membership and does not create a payment order.

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`, parent.`id`, 'A', '设置套餐', '', 2, 'user.user/setMembership', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_consumer_set_membership', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`type` = 'C'
  AND parent.`paths` = 'lists'
  AND parent.`component` = 'consumer/lists/index'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` existing_menu
    WHERE existing_menu.`tenant_id` = parent.`tenant_id`
      AND (existing_menu.`source_menu_key` = 'core_tenant_consumer_set_membership'
        OR existing_menu.`perms` = 'user.user/setMembership')
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`, parent.`id`, 'A', '套餐列表', '', 3, 'user.user/membershipPlans', '', '', '', '', 0, 1, 0, '', 'core', 'core_tenant_consumer_membership_plans', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`type` = 'C'
  AND parent.`paths` = 'lists'
  AND parent.`component` = 'consumer/lists/index'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` existing_menu
    WHERE existing_menu.`tenant_id` = parent.`tenant_id`
      AND (existing_menu.`source_menu_key` = 'core_tenant_consumer_membership_plans'
        OR existing_menu.`perms` = 'user.user/membershipPlans')
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT role.`id`, menu.`id`
FROM `la_tenant_system_role` role
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE menu.`source_menu_key` IN ('core_tenant_consumer_set_membership', 'core_tenant_consumer_membership_plans')
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
