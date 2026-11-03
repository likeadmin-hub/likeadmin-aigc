-- Soft-delete finished works without removing billing or generation evidence.
SET @cw_delete_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_copywriting_task' AND COLUMN_NAME = 'delete_time') = 0, 'ALTER TABLE `la_aigc_copywriting_task` ADD COLUMN `delete_time` int NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE cw_delete_stmt FROM @cw_delete_sql;
EXECUTE cw_delete_stmt;
DEALLOCATE PREPARE cw_delete_stmt;
