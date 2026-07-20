SET @aigc_image_task_provider_params_sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_task') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_task' AND COLUMN_NAME = 'provider_params_json') = 0,
    'ALTER TABLE `la_aigc_image_task` ADD COLUMN `provider_params_json` text COMMENT ''供应商透传参数'' AFTER `reference_images`',
    'SELECT 1'
);
PREPARE aigc_image_task_provider_params_stmt FROM @aigc_image_task_provider_params_sql;
EXECUTE aigc_image_task_provider_params_stmt;
DEALLOCATE PREPARE aigc_image_task_provider_params_stmt;
