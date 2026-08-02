-- Keep the core system application enabled for every existing tenant.
INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT
    `t`.`id`,
    'system_default',
    `a`.`current_version`,
    'paid',
    'on',
    'enabled',
    0,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM `la_tenant` `t`
INNER JOIN `la_app` `a` ON `a`.`code` = 'system_default' AND `a`.`status` = 'installed'
ON DUPLICATE KEY UPDATE
    `version` = VALUES(`version`),
    `buy_status` = VALUES(`buy_status`),
    `shelf_status` = VALUES(`shelf_status`),
    `enable_status` = VALUES(`enable_status`),
    `expire_time` = VALUES(`expire_time`),
    `update_time` = VALUES(`update_time`);
