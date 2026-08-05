SET @db_name = DATABASE();

SET @storyboard_uk_cols = (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'la_aigc_short_drama_storyboard'
    AND INDEX_NAME = 'uk_task_shot'
);

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_short_drama_storyboard') = 0,
  'SELECT 1',
  IF(
    @storyboard_uk_cols IS NULL,
    'ALTER TABLE `la_aigc_short_drama_storyboard` ADD UNIQUE KEY `uk_task_shot` (`tenant_id`,`task_id`,`shot_id`,`delete_time`)',
    IF(
      @storyboard_uk_cols = 'tenant_id,task_id,shot_id,delete_time',
      'SELECT 1',
      'ALTER TABLE `la_aigc_short_drama_storyboard` DROP INDEX `uk_task_shot`, ADD UNIQUE KEY `uk_task_shot` (`tenant_id`,`task_id`,`shot_id`,`delete_time`)'
    )
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
