SET @video_task_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task');

SET @video_app_task_sql := IF(@video_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'app_task_id') = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一应用任务ID'' AFTER `id`, ADD KEY `idx_app_task` (`app_task_id`)', 'SELECT 1');
PREPARE video_app_task_stmt FROM @video_app_task_sql; EXECUTE video_app_task_stmt; DEALLOCATE PREPARE video_app_task_stmt;

SET @video_consumption_sql := IF(@video_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'consumption_id') = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `consumption_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一消耗日志ID'' AFTER `app_task_id`, ADD KEY `idx_consumption` (`consumption_id`)', 'SELECT 1');
PREPARE video_consumption_stmt FROM @video_consumption_sql; EXECUTE video_consumption_stmt; DEALLOCATE PREPARE video_consumption_stmt;

SET @video_product_sql := IF(@video_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'market_product_id') = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `market_product_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场商品ID'' AFTER `consumption_id`', 'SELECT 1');
PREPARE video_product_stmt FROM @video_product_sql; EXECUTE video_product_stmt; DEALLOCATE PREPARE video_product_stmt;

SET @video_sku_sql := IF(@video_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'market_sku_id') = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `market_sku_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场SKU ID'' AFTER `market_product_id`', 'SELECT 1');
PREPARE video_sku_stmt FROM @video_sku_sql; EXECUTE video_sku_stmt; DEALLOCATE PREPARE video_sku_stmt;

SET @video_model_sql := IF(@video_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'model_json') = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `model_json` text COMMENT ''市场模型快照'' AFTER `provider_task_id`', 'SELECT 1');
PREPARE video_model_stmt FROM @video_model_sql; EXECUTE video_model_stmt; DEALLOCATE PREPARE video_model_stmt;

SET @video_price_sql := IF(@video_task_exists > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'pricing_snapshot') = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `pricing_snapshot` text COMMENT ''市场价格快照'' AFTER `model_json`', 'SELECT 1');
PREPARE video_price_stmt FROM @video_price_sql; EXECUTE video_price_stmt; DEALLOCATE PREPARE video_price_stmt;

INSERT INTO `la_dev_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT 'AIGC任务消耗补偿', 1, 1, '补偿刷新异步生成任务并结算消耗日志', 'ai:usage_reconcile', '--limit=20', 1, '* * * * *', NULL, NULL, '0', '0', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), NULL
WHERE NOT EXISTS (SELECT 1 FROM `la_dev_crontab` WHERE `command` = 'ai:usage_reconcile' AND `delete_time` IS NULL);
