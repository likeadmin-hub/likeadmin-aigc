SET @table_schema = DATABASE();
SET @table_name = 'la_update_source';
SET @column_name = 'dev_mode';
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `la_update_source` ADD COLUMN `dev_mode` tinyint NOT NULL DEFAULT 0 COMMENT ''开发模式：1开启 0关闭'' AFTER `online_license_key`',
        'ALTER TABLE `la_update_source` MODIFY COLUMN `dev_mode` tinyint NOT NULL DEFAULT 0 COMMENT ''开发模式：1开启 0关闭'''
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @table_schema
      AND TABLE_NAME = @table_name
      AND COLUMN_NAME = @column_name
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
