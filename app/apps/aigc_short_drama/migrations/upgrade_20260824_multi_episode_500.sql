-- A 500-episode plan includes per-episode plots, subjects, scenes, and storyboards.
-- MEDIUMTEXT can be exhausted by a complete plan, so preserve the full generated result.
SET @short_drama_script_task_result_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_script_task' AND COLUMN_NAME = 'result_json' AND DATA_TYPE <> 'longtext') = 1,
  'ALTER TABLE `la_aigc_short_drama_script_task` MODIFY COLUMN `result_json` longtext',
  'SELECT 1'
);
PREPARE short_drama_script_task_result_stmt FROM @short_drama_script_task_result_sql;
EXECUTE short_drama_script_task_result_stmt;
DEALLOCATE PREPARE short_drama_script_task_result_stmt;

SET @short_drama_plan_json_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_plan_version' AND COLUMN_NAME = 'plan_json' AND DATA_TYPE <> 'longtext') = 1,
  'ALTER TABLE `la_aigc_short_drama_plan_version` MODIFY COLUMN `plan_json` longtext',
  'SELECT 1'
);
PREPARE short_drama_plan_json_stmt FROM @short_drama_plan_json_sql;
EXECUTE short_drama_plan_json_stmt;
DEALLOCATE PREPARE short_drama_plan_json_stmt;

SET @short_drama_storyboard_json_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_plan_version' AND COLUMN_NAME = 'storyboard_json' AND DATA_TYPE <> 'longtext') = 1,
  'ALTER TABLE `la_aigc_short_drama_plan_version` MODIFY COLUMN `storyboard_json` longtext',
  'SELECT 1'
);
PREPARE short_drama_storyboard_json_stmt FROM @short_drama_storyboard_json_sql;
EXECUTE short_drama_storyboard_json_stmt;
DEALLOCATE PREPARE short_drama_storyboard_json_stmt;

SET @short_drama_generation_task_result_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'result_json' AND DATA_TYPE <> 'longtext') = 1,
  'ALTER TABLE `la_aigc_short_drama_generation_task` MODIFY COLUMN `result_json` longtext',
  'SELECT 1'
);
PREPARE short_drama_generation_task_result_stmt FROM @short_drama_generation_task_result_sql;
EXECUTE short_drama_generation_task_result_stmt;
DEALLOCATE PREPARE short_drama_generation_task_result_stmt;
