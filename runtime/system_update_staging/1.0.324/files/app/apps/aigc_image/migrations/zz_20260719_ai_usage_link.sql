SET @has_ai_app_task_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_task' AND COLUMN_NAME = 'app_task_id');
SET @ai_app_task_id_sql := IF(@has_ai_app_task_id = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一应用任务ID'' AFTER `id`, ADD KEY `idx_app_task` (`app_task_id`)', 'SELECT 1');
PREPARE ai_app_task_id_stmt FROM @ai_app_task_id_sql;
EXECUTE ai_app_task_id_stmt;
DEALLOCATE PREPARE ai_app_task_id_stmt;

SET @has_consumption_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_billing' AND COLUMN_NAME = 'consumption_id');
SET @consumption_id_sql := IF(@has_consumption_id = 0, 'ALTER TABLE `la_aigc_image_billing` ADD COLUMN `consumption_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一消耗日志ID'' AFTER `id`, ADD KEY `idx_consumption` (`consumption_id`)', 'SELECT 1');
PREPARE consumption_id_stmt FROM @consumption_id_sql;
EXECUTE consumption_id_stmt;
DEALLOCATE PREPARE consumption_id_stmt;
