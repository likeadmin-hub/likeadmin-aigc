SET @db_name = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call')>0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call' AND COLUMN_NAME='delivery_item_id')=0,
  'ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD COLUMN `delivery_item_id` bigint unsigned NOT NULL DEFAULT 0 AFTER `tool_code`, ADD COLUMN `attempt_no` int unsigned NOT NULL DEFAULT 1 AFTER `delivery_item_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call')>0 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call' AND INDEX_NAME='idx_delivery_item')=0,
  'ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD KEY `idx_delivery_item` (`tenant_id`,`user_id`,`delivery_item_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call' AND INDEX_NAME='idx_provider_task')=0,
  'ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD KEY `idx_provider_task` (`tenant_id`,`provider_task_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
