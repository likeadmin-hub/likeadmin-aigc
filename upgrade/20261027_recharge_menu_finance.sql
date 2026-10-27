-- Move only core menus. Split-table tenants are reconciled by MenuLogic.
UPDATE `la_tenant_system_menu` AS recharge
SET `pid` = COALESCE((
    SELECT finance.`id` FROM (SELECT `id`,`tenant_id`,`pid`,`type`,`paths`,`source` FROM `la_tenant_system_menu`) AS finance
    WHERE finance.`tenant_id` = recharge.`tenant_id` AND finance.`pid` = 0
      AND finance.`type` = 'M' AND finance.`paths` = 'finance' AND finance.`source` <> 'tenant'
    ORDER BY finance.`id` LIMIT 1
), recharge.`pid`)
WHERE recharge.`perms` = 'recharge.recharge/getConfig' AND recharge.`source` <> 'tenant';

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`, `menu_id`)
SELECT roles.`role_id`, (
    SELECT moved.`pid` FROM `la_tenant_system_menu` AS moved WHERE moved.`id` = roles.`menu_id`
)
FROM `la_tenant_system_role_menu` AS roles
WHERE roles.`menu_id` IN (
    SELECT recharge.`id` FROM `la_tenant_system_menu` AS recharge
    WHERE recharge.`perms` = 'recharge.recharge/getConfig' AND recharge.`source` <> 'tenant'
      AND EXISTS (SELECT 1 FROM `la_tenant_system_menu` AS finance
        WHERE finance.`id` = recharge.`pid` AND finance.`tenant_id` = recharge.`tenant_id`
          AND finance.`pid` = 0 AND finance.`type` = 'M' AND finance.`paths` = 'finance' AND finance.`source` <> 'tenant')
);
