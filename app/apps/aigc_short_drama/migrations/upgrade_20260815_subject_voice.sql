SET @short_drama_subject_table = 'la_aigc_short_drama_subject';

SET @short_drama_sql = (
  SELECT IF(
    COUNT(*) = 0,
    CONCAT('ALTER TABLE `', @short_drama_subject_table, '` ADD COLUMN `voice_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''绑定音色ID'' AFTER `age_stage`'),
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @short_drama_subject_table AND COLUMN_NAME = 'voice_id'
);
PREPARE short_drama_stmt FROM @short_drama_sql;
EXECUTE short_drama_stmt;
DEALLOCATE PREPARE short_drama_stmt;

SET @short_drama_sql = (
  SELECT IF(
    COUNT(*) = 0,
    CONCAT('ALTER TABLE `', @short_drama_subject_table, '` ADD COLUMN `voice_name` varchar(80) NOT NULL DEFAULT '''' COMMENT ''绑定音色名称'' AFTER `voice_id`'),
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @short_drama_subject_table AND COLUMN_NAME = 'voice_name'
);
PREPARE short_drama_stmt FROM @short_drama_sql;
EXECUTE short_drama_stmt;
DEALLOCATE PREPARE short_drama_stmt;

SET @short_drama_sql = (
  SELECT IF(
    COUNT(*) = 0,
    CONCAT('ALTER TABLE `', @short_drama_subject_table, '` ADD COLUMN `voice_label` varchar(160) NOT NULL DEFAULT '''' COMMENT ''绑定音色标签'' AFTER `voice_name`'),
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @short_drama_subject_table AND COLUMN_NAME = 'voice_label'
);
PREPARE short_drama_stmt FROM @short_drama_sql;
EXECUTE short_drama_stmt;
DEALLOCATE PREPARE short_drama_stmt;

SET @short_drama_sql = (
  SELECT IF(
    COUNT(*) = 0,
    CONCAT('ALTER TABLE `', @short_drama_subject_table, '` ADD COLUMN `voice_source` varchar(20) NOT NULL DEFAULT '''' COMMENT ''音色来源 official/mine'' AFTER `voice_label`'),
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @short_drama_subject_table AND COLUMN_NAME = 'voice_source'
);
PREPARE short_drama_stmt FROM @short_drama_sql;
EXECUTE short_drama_stmt;
DEALLOCATE PREPARE short_drama_stmt;
