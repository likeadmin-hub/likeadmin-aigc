SET @table_schema = DATABASE();
SET @table_name = 'la_tenant';
SET @column_name = 'access_mode';
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `la_tenant` ADD COLUMN `access_mode` varchar(20) NOT NULL DEFAULT ''subdomain'' COMMENT ''访问方式:subdomain自动子域名,id租户ID,alias别名'' AFTER `domain_alias_enable`',
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
UPDATE `la_tenant` SET `access_mode` = 'alias' WHERE `domain_alias_enable` = 0 AND IFNULL(`domain_alias`, '') <> '';
