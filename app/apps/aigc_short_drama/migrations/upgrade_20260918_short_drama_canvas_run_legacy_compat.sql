-- The 20260914 short-drama canvas release created canvas_run with a workspace
-- schema. The 20260918 canvas release reuses the table for document/node runs.
-- CREATE TABLE IF NOT EXISTS cannot upgrade that earlier table, so add the
-- newer additive fields before the task-link migration is evaluated.
SET @canvas_run_canvas_id_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'canvas_id') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `canvas_id` int unsigned NOT NULL DEFAULT 0 AFTER `user_id`', 'SELECT 1');
PREPARE canvas_run_canvas_id_stmt FROM @canvas_run_canvas_id_sql;
EXECUTE canvas_run_canvas_id_stmt;
DEALLOCATE PREPARE canvas_run_canvas_id_stmt;

SET @canvas_run_node_id_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'node_id') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `node_id` varchar(64) NOT NULL DEFAULT '''' AFTER `canvas_id`', 'SELECT 1');
PREPARE canvas_run_node_id_stmt FROM @canvas_run_node_id_sql;
EXECUTE canvas_run_node_id_stmt;
DEALLOCATE PREPARE canvas_run_node_id_stmt;

SET @canvas_run_node_type_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'node_type') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `node_type` varchar(16) NOT NULL DEFAULT '''' AFTER `node_id`', 'SELECT 1');
PREPARE canvas_run_node_type_stmt FROM @canvas_run_node_type_sql;
EXECUTE canvas_run_node_type_stmt;
DEALLOCATE PREPARE canvas_run_node_type_stmt;

SET @canvas_run_provider_task_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'provider_task_id') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `provider_task_id` varchar(100) NOT NULL DEFAULT '''' AFTER `node_type`', 'SELECT 1');
PREPARE canvas_run_provider_task_stmt FROM @canvas_run_provider_task_sql;
EXECUTE canvas_run_provider_task_stmt;
DEALLOCATE PREPARE canvas_run_provider_task_stmt;

SET @canvas_run_progress_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'progress') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `progress` tinyint unsigned NOT NULL DEFAULT 0 AFTER `status`', 'SELECT 1');
PREPARE canvas_run_progress_stmt FROM @canvas_run_progress_sql;
EXECUTE canvas_run_progress_stmt;
DEALLOCATE PREPARE canvas_run_progress_stmt;

SET @canvas_run_request_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'request_json') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `request_json` longtext AFTER `progress`', 'SELECT 1');
PREPARE canvas_run_request_stmt FROM @canvas_run_request_sql;
EXECUTE canvas_run_request_stmt;
DEALLOCATE PREPARE canvas_run_request_stmt;

SET @canvas_run_error_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'error') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `error` varchar(500) NOT NULL DEFAULT '''' AFTER `result_json`', 'SELECT 1');
PREPARE canvas_run_error_stmt FROM @canvas_run_error_sql;
EXECUTE canvas_run_error_stmt;
DEALLOCATE PREPARE canvas_run_error_stmt;

SET @canvas_run_delete_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'delete_time') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0 AFTER `update_time`', 'SELECT 1');
PREPARE canvas_run_delete_stmt FROM @canvas_run_delete_sql;
EXECUTE canvas_run_delete_stmt;
DEALLOCATE PREPARE canvas_run_delete_stmt;

SET @canvas_run_owner_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND INDEX_NAME = 'idx_canvas_node') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD KEY `idx_canvas_node` (`tenant_id`,`user_id`,`canvas_id`,`node_id`,`delete_time`)', 'SELECT 1');
PREPARE canvas_run_owner_index_stmt FROM @canvas_run_owner_index_sql;
EXECUTE canvas_run_owner_index_stmt;
DEALLOCATE PREPARE canvas_run_owner_index_stmt;

SET @canvas_run_status_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND INDEX_NAME = 'idx_status') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` ADD KEY `idx_status` (`tenant_id`,`status`,`delete_time`)', 'SELECT 1');
PREPARE canvas_run_status_index_stmt FROM @canvas_run_status_index_sql;
EXECUTE canvas_run_status_index_stmt;
DEALLOCATE PREPARE canvas_run_status_index_stmt;
