SET @aigc_dh_table = REPLACE('`la_aigc_digital_human_channel_spec`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_table, '` ADD COLUMN `billing_unit` varchar(20) NOT NULL DEFAULT ''second'' COMMENT ''计费单位 second/count'' AFTER `tenant_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_table AND COLUMN_NAME = 'billing_unit');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_table = REPLACE('`la_aigc_digital_human_billing`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_table, '` ADD COLUMN `billing_type` varchar(30) NOT NULL DEFAULT ''generate'' COMMENT ''计费类型 generate/avatar_clone/voice_clone'' AFTER `ratio`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_table AND COLUMN_NAME = 'billing_type');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_table = REPLACE('`la_aigc_digital_human_billing`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_table, '` ADD COLUMN `billing_unit` varchar(20) NOT NULL DEFAULT ''count'' COMMENT ''计费单位 second/count'' AFTER `billing_type`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_table AND COLUMN_NAME = 'billing_unit');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_table = REPLACE('`la_aigc_digital_human_billing`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_table, '` ADD COLUMN `extra_json` text COMMENT ''计费扩展信息'' AFTER `user_point_sn`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_table AND COLUMN_NAME = 'extra_json');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

UPDATE `la_aigc_digital_human_channel_spec`
SET `billing_unit` = 'second',
    `platform_unit_cost` = CASE WHEN `platform_unit_cost` >= 1 THEN ROUND(`platform_unit_cost` / 30, 2) ELSE `platform_unit_cost` END,
    `tenant_unit_price` = CASE WHEN `tenant_unit_price` >= 1 THEN ROUND(`tenant_unit_price` / 30, 2) ELSE `tenant_unit_price` END,
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0;
