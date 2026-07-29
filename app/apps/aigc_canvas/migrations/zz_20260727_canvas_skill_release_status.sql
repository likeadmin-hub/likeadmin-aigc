SET @db_name = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'release_status') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `release_status` varchar(24) NOT NULL DEFAULT ''active'' COMMENT ''draft/testing/canary/active/paused/archived'' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND INDEX_NAME = 'idx_tenant_active_skill') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD KEY `idx_tenant_active_skill` (`tenant_id`,`status`,`release_status`,`delete_time`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
