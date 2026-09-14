-- Canonical live package state is NULL for ThinkPHP SoftDelete.
-- Preserve deleted timestamps; normalize only legacy live rows.
SET @sql := IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package'), 'ALTER TABLE `la_tenant_package` MODIFY COLUMN `delete_time` int unsigned DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package'), 'UPDATE `la_tenant_package` SET `delete_time`=NULL WHERE `delete_time`=0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_power_package'), 'ALTER TABLE `la_tenant_power_package` MODIFY COLUMN `delete_time` int unsigned DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_power_package'), 'UPDATE `la_tenant_power_package` SET `delete_time`=NULL WHERE `delete_time`=0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
UPDATE `la_system_menu` SET `name`='算力明细', `component`='tenant/power_consume/index', `update_time`=UNIX_TIMESTAMP()
WHERE `source_menu_key`='core_tenant_power_consume_platform' AND `source`='core';
