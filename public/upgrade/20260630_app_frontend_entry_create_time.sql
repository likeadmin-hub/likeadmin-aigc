SET @app_frontend_entry_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_app_frontend_entry');
SET @app_frontend_entry_create_time_sql = (SELECT IF(@app_frontend_entry_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_app_frontend_entry` ADD COLUMN `create_time` int unsigned NOT NULL DEFAULT 0 AFTER `meta`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_app_frontend_entry' AND COLUMN_NAME = 'create_time');
PREPARE app_frontend_entry_create_time_stmt FROM @app_frontend_entry_create_time_sql;
EXECUTE app_frontend_entry_create_time_stmt;
DEALLOCATE PREPARE app_frontend_entry_create_time_stmt;
