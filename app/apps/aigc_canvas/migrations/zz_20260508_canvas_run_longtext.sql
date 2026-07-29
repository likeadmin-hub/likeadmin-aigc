-- Expand canvas run logs for streamed text and multi-image reference payloads.
-- This migration can be replayed by a system repair package.

SET @db_name = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db_name
     AND TABLE_NAME = 'la_aigc_canvas_run'
     AND COLUMN_NAME = 'params_json'
     AND DATA_TYPE <> 'longtext') > 0,
  'ALTER TABLE `la_aigc_canvas_run` MODIFY COLUMN `params_json` longtext COMMENT ''调用参数''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db_name
     AND TABLE_NAME = 'la_aigc_canvas_run'
     AND COLUMN_NAME = 'result_json'
     AND DATA_TYPE <> 'longtext') > 0,
  'ALTER TABLE `la_aigc_canvas_run` MODIFY COLUMN `result_json` longtext COMMENT ''执行结果''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
