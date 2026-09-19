-- Change only the default for future inserts; existing authored shots remain unchanged.
SET @shot_duration_sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'recommended_duration_seconds') = 1,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ALTER COLUMN `recommended_duration_seconds` SET DEFAULT 5.00',
  'SELECT 1'
);
PREPARE shot_duration_stmt FROM @shot_duration_sql;
EXECUTE shot_duration_stmt;
DEALLOCATE PREPARE shot_duration_stmt;
