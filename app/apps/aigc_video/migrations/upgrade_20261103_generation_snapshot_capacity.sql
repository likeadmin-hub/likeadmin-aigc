-- Complete frozen model contracts can exceed the 64 KiB TEXT limit.
-- Widen only existing TEXT snapshot columns; retain data, comments, charset,
-- collation, nullability and defaults. Missing optional apps and larger types
-- are left alone. No task, price, balance or provider configuration is changed.

SET @generation_snapshot_capacity_sql = (
  SELECT CONCAT('ALTER TABLE `la_aigc_video_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT CHARACTER SET `', CHARACTER_SET_NAME, '` COLLATE `', COLLATION_NAME, '`',
    IF(IS_NULLABLE = 'YES', ' NULL', ' NOT NULL'),
    IF(COLUMN_DEFAULT IS NULL, IF(IS_NULLABLE = 'YES', ' DEFAULT NULL', ''), CONCAT(' DEFAULT ', CHAR(39), REPLACE(IF(FIND_IN_SET('NO_BACKSLASH_ESCAPES', @@SESSION.sql_mode), COLUMN_DEFAULT, REPLACE(COLUMN_DEFAULT, CHAR(92), CONCAT(CHAR(92), CHAR(92)))), CHAR(39), CONCAT(CHAR(39), CHAR(39))), CHAR(39))),
    ' COMMENT ', CHAR(39), REPLACE(IF(FIND_IN_SET('NO_BACKSLASH_ESCAPES', @@SESSION.sql_mode), COLUMN_COMMENT, REPLACE(COLUMN_COMMENT, CHAR(92), CONCAT(CHAR(92), CHAR(92)))), CHAR(39), CONCAT(CHAR(39), CHAR(39))), CHAR(39))
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_task' AND COLUMN_NAME = 'pricing_snapshot' AND DATA_TYPE = 'text' AND EXTRA = ''
);
SET @generation_snapshot_capacity_sql = COALESCE(@generation_snapshot_capacity_sql, 'SELECT 1');
PREPARE generation_snapshot_capacity_stmt FROM @generation_snapshot_capacity_sql;
EXECUTE generation_snapshot_capacity_stmt;
DEALLOCATE PREPARE generation_snapshot_capacity_stmt;
