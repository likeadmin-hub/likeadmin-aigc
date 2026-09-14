-- 1.0.305 compatibility repair for installations that already recorded older migrations.
SET @prefix := 'la_';
-- Repair legacy tables before historical CREATE TABLE IF NOT EXISTS/INSERT statements run.
-- Ensure legacy tenant menu tables support the soft-delete field used by menu migrations.
SET @table_name := CONCAT(@prefix, 'tenant_system_menu');
SET @table_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name);
SET @sql := (SELECT IF(@table_exists > 0 AND COUNT(*) = 0, CONCAT('ALTER TABLE `', @table_name, '` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'delete_time');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := CONCAT(@prefix, 'aigc_product_promo_video_type');
SET @sql := (SELECT IF(COUNT(*) > 0 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'delete_time') = 0, CONCAT('ALTER TABLE `', @table_name, '` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0'), 'SELECT 1') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @table_name := CONCAT(@prefix, 'aigc_product_promo_video_task');
SET @sql := (SELECT IF(COUNT(*) > 0 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'delete_time') = 0, CONCAT('ALTER TABLE `', @table_name, '` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0'), 'SELECT 1') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @table_name := CONCAT(@prefix, 'aigc_product_promo_video_result');
SET @sql := (SELECT IF(COUNT(*) > 0 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'delete_time') = 0, CONCAT('ALTER TABLE `', @table_name, '` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0'), 'SELECT 1') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @table_name := CONCAT(@prefix, 'tenant_power_package');
SET @table_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name);
SET @sql := (SELECT IF(@table_exists > 0 AND COUNT(*) = 0, CONCAT('ALTER TABLE `', @table_name, '` ADD COLUMN `delete_time` int unsigned DEFAULT NULL'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'delete_time');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @table_name := CONCAT(@prefix, 'dev_crontab');
SET @table_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name);
SET @sql := (SELECT IF(@table_exists > 0 AND COUNT(*) = 0, CONCAT('ALTER TABLE `', @table_name, '` ADD COLUMN `delete_time` int unsigned DEFAULT NULL'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND COLUMN_NAME = 'delete_time');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
