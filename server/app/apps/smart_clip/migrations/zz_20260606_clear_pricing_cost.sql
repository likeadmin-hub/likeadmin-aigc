SET @pricing_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_smart_clip_channel_spec');
SET @pricing_sql = (SELECT IF(@pricing_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_smart_clip_channel_spec` ADD COLUMN `upstream_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''每单位上游成本'' AFTER `height`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_smart_clip_channel_spec' AND COLUMN_NAME = 'upstream_unit_cost');
PREPARE stmt FROM @pricing_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pricing_sql = (SELECT IF(@pricing_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_smart_clip_channel_spec` ADD COLUMN `upstream_cost_text` varchar(500) NOT NULL DEFAULT '''' COMMENT ''上游成本说明'' AFTER `tenant_unit_price`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_smart_clip_channel_spec' AND COLUMN_NAME = 'upstream_cost_text');
PREPARE stmt FROM @pricing_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pricing_sql = (SELECT IF(@pricing_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_smart_clip_channel_spec` ADD COLUMN `cost_source_url` varchar(500) NOT NULL DEFAULT '''' COMMENT ''成本来源链接'' AFTER `upstream_cost_text`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_smart_clip_channel_spec' AND COLUMN_NAME = 'cost_source_url');
PREPARE stmt FROM @pricing_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
