SET @table_schema = DATABASE();
SET @table_name = 'la_tenant';
SET @column_name = 'allow_local_storage';
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `la_tenant` ADD COLUMN `allow_local_storage` tinyint(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT ''允许租户使用本地存储'' AFTER `allow_custom_storage`',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @table_schema
      AND TABLE_NAME = @table_name
      AND COLUMN_NAME = @column_name
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
