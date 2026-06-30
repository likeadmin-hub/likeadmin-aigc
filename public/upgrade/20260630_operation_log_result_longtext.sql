SET @operation_log_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_operation_log');
SET @operation_log_result_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_operation_log' AND COLUMN_NAME = 'result');
SET @operation_log_sql = IF(@operation_log_exists > 0 AND @operation_log_result_exists > 0, 'ALTER TABLE `la_operation_log` MODIFY COLUMN `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT ''请求结果''', 'SELECT 1');
PREPARE stmt FROM @operation_log_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
