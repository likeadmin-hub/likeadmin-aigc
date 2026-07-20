SET @has_sd_app_task_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'app_task_id');
SET @sd_app_task_sql := IF(@has_sd_app_task_id = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一应用任务ID'' AFTER `id`, ADD KEY `idx_app_task` (`app_task_id`)', 'SELECT 1');
PREPARE sd_app_task_stmt FROM @sd_app_task_sql; EXECUTE sd_app_task_stmt; DEALLOCATE PREPARE sd_app_task_stmt;

SET @has_sd_consumption_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'consumption_id');
SET @sd_consumption_sql := IF(@has_sd_consumption_id = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `consumption_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一消耗日志ID'' AFTER `app_task_id`, ADD KEY `idx_consumption` (`consumption_id`)', 'SELECT 1');
PREPARE sd_consumption_stmt FROM @sd_consumption_sql; EXECUTE sd_consumption_stmt; DEALLOCATE PREPARE sd_consumption_stmt;

SET @has_sd_market_product_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'market_product_id');
SET @sd_market_product_sql := IF(@has_sd_market_product_id = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `market_product_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场商品ID'' AFTER `consumption_id`', 'SELECT 1');
PREPARE sd_market_product_stmt FROM @sd_market_product_sql; EXECUTE sd_market_product_stmt; DEALLOCATE PREPARE sd_market_product_stmt;

SET @has_sd_market_sku_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'market_sku_id');
SET @sd_market_sku_sql := IF(@has_sd_market_sku_id = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `market_sku_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''算力市场SKU ID'' AFTER `market_product_id`', 'SELECT 1');
PREPARE sd_market_sku_stmt FROM @sd_market_sku_sql; EXECUTE sd_market_sku_stmt; DEALLOCATE PREPARE sd_market_sku_stmt;
