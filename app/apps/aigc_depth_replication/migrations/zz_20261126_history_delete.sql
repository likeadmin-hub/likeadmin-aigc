SET @depth_history_ddl = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `la_aigc_depth_replication_job` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'la_aigc_depth_replication_job' AND column_name = 'delete_time');
PREPARE depth_history_stmt FROM @depth_history_ddl;
EXECUTE depth_history_stmt;
DEALLOCATE PREPARE depth_history_stmt;
