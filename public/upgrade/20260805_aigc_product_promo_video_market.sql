SET @promo_task_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task');
SET @promo_config_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_config');

SET @promo_market_enabled_sql := IF(@promo_config_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_config' AND COLUMN_NAME = 'market_enabled') = 0, 'ALTER TABLE `la_aigc_product_promo_video_config` ADD COLUMN `market_enabled` tinyint NOT NULL DEFAULT 1 COMMENT ''是否启用算力市场'' AFTER `status`', 'SELECT 1');
PREPARE promo_market_enabled_stmt FROM @promo_market_enabled_sql; EXECUTE promo_market_enabled_stmt; DEALLOCATE PREPARE promo_market_enabled_stmt;
SET @promo_market_force_sql := IF(@promo_config_exists > 0, 'UPDATE `la_aigc_product_promo_video_config` SET `market_enabled` = 1', 'SELECT 1');
PREPARE promo_market_force_stmt FROM @promo_market_force_sql; EXECUTE promo_market_force_stmt; DEALLOCATE PREPARE promo_market_force_stmt;

SET @promo_app_task_sql := IF(@promo_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task' AND COLUMN_NAME = 'app_task_id') = 0, 'ALTER TABLE `la_aigc_product_promo_video_task` ADD COLUMN `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一应用任务ID'' AFTER `video_task_id`, ADD KEY `idx_app_task` (`app_task_id`)', 'SELECT 1');
PREPARE promo_app_task_stmt FROM @promo_app_task_sql; EXECUTE promo_app_task_stmt; DEALLOCATE PREPARE promo_app_task_stmt;

SET @promo_consumption_sql := IF(@promo_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task' AND COLUMN_NAME = 'consumption_id') = 0, 'ALTER TABLE `la_aigc_product_promo_video_task` ADD COLUMN `consumption_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场消耗记录ID'' AFTER `app_task_id`, ADD KEY `idx_consumption` (`consumption_id`)', 'SELECT 1');
PREPARE promo_consumption_stmt FROM @promo_consumption_sql; EXECUTE promo_consumption_stmt; DEALLOCATE PREPARE promo_consumption_stmt;

SET @promo_product_sql := IF(@promo_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task' AND COLUMN_NAME = 'market_product_id') = 0, 'ALTER TABLE `la_aigc_product_promo_video_task` ADD COLUMN `market_product_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场商品ID'' AFTER `consumption_id`', 'SELECT 1');
PREPARE promo_product_stmt FROM @promo_product_sql; EXECUTE promo_product_stmt; DEALLOCATE PREPARE promo_product_stmt;

SET @promo_sku_sql := IF(@promo_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task' AND COLUMN_NAME = 'market_sku_id') = 0, 'ALTER TABLE `la_aigc_product_promo_video_task` ADD COLUMN `market_sku_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场SKU ID'' AFTER `market_product_id`', 'SELECT 1');
PREPARE promo_sku_stmt FROM @promo_sku_sql; EXECUTE promo_sku_stmt; DEALLOCATE PREPARE promo_sku_stmt;

SET @promo_snapshot_sql := IF(@promo_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task' AND COLUMN_NAME = 'pricing_snapshot') = 0, 'ALTER TABLE `la_aigc_product_promo_video_task` ADD COLUMN `pricing_snapshot` text COMMENT ''市场价格快照'' AFTER `market_sku_id`', 'SELECT 1');
PREPARE promo_snapshot_stmt FROM @promo_snapshot_sql; EXECUTE promo_snapshot_stmt; DEALLOCATE PREPARE promo_snapshot_stmt;

SET @promo_billing_sql := IF(@promo_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_task' AND COLUMN_NAME = 'billing_status') = 0, 'ALTER TABLE `la_aigc_product_promo_video_task` ADD COLUMN `billing_status` varchar(30) NOT NULL DEFAULT ''none'' COMMENT ''市场结算状态'' AFTER `pricing_snapshot`', 'SELECT 1');
PREPARE promo_billing_stmt FROM @promo_billing_sql; EXECUTE promo_billing_stmt; DEALLOCATE PREPARE promo_billing_stmt;
