SET @has_short_drama_app_task := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_script_task' AND COLUMN_NAME = 'app_task_id');
SET @short_drama_app_task_sql := IF(@has_short_drama_app_task = 0, 'ALTER TABLE `la_aigc_short_drama_script_task` ADD COLUMN `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一应用任务ID'' AFTER `id`, ADD KEY `idx_app_task` (`app_task_id`)', 'SELECT 1');
PREPARE short_drama_app_task_stmt FROM @short_drama_app_task_sql;
EXECUTE short_drama_app_task_stmt;
DEALLOCATE PREPARE short_drama_app_task_stmt;
