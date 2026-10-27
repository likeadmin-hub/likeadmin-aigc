-- Move the existing core menu while preserving its ID and role assignments.
-- Split-table tenants are reconciled by MenuLogic when their menus are loaded.
UPDATE `la_tenant_system_menu` AS recharge
JOIN `la_tenant_system_menu` AS finance
  ON finance.`tenant_id` = recharge.`tenant_id`
 AND finance.`pid` = 0 AND finance.`type` = 'M' AND finance.`paths` = 'finance'
 AND finance.`source` <> 'tenant'
SET recharge.`pid` = finance.`id`, recharge.`update_time` = UNIX_TIMESTAMP()
WHERE recharge.`perms` = 'recharge.recharge/getConfig'
  AND recharge.`source` <> 'tenant' AND recharge.`pid` <> finance.`id`;

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`, `menu_id`)
SELECT roles.`role_id`, finance.`id`
FROM `la_tenant_system_menu` AS recharge
JOIN `la_tenant_system_menu` AS finance ON finance.`id` = recharge.`pid`
JOIN `la_tenant_system_role_menu` AS roles ON roles.`menu_id` = recharge.`id`
WHERE recharge.`perms` = 'recharge.recharge/getConfig'
  AND recharge.`source` <> 'tenant' AND finance.`source` <> 'tenant'
  AND finance.`type` = 'M' AND finance.`paths` = 'finance';
