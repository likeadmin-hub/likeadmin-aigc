-- 小程序租户管理扩展：上传方式、其他设置和备案信息。可重复执行。
SET NAMES utf8mb4;
SET @db_name = DATABASE();
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_credentials' AND COLUMN_NAME='upload_mode')=0,
  'ALTER TABLE `la_wechat_credentials` ADD COLUMN `upload_mode` varchar(20) NOT NULL DEFAULT ''key'' AFTER `upload_private_pem`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_mnp_versions' AND COLUMN_NAME='upload_mode')=0,
  'ALTER TABLE `la_wechat_mnp_versions` ADD COLUMN `upload_mode` varchar(20) NOT NULL DEFAULT ''template'' AFTER `ext_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_mnp_versions' AND COLUMN_NAME='upload_status')=0,
  'ALTER TABLE `la_wechat_mnp_versions` ADD COLUMN `upload_status` varchar(20) NOT NULL DEFAULT ''success'' AFTER `upload_mode`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_mnp_versions' AND COLUMN_NAME='upload_command')=0,
  'ALTER TABLE `la_wechat_mnp_versions` ADD COLUMN `upload_command` text NULL AFTER `upload_status`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_credentials' AND COLUMN_NAME='settings_json')=0,
  'ALTER TABLE `la_wechat_credentials` ADD COLUMN `settings_json` longtext NULL AFTER `upload_mode`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_mnp_versions' AND COLUMN_NAME='runtime_config_hash')=0,
  'ALTER TABLE `la_wechat_mnp_versions` ADD COLUMN `runtime_config_hash` varchar(64) NOT NULL DEFAULT '''' AFTER `upload_command`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_mnp_versions' AND COLUMN_NAME='api_base_url')=0,
  'ALTER TABLE `la_wechat_mnp_versions` ADD COLUMN `api_base_url` varchar(255) NOT NULL DEFAULT '''' AFTER `runtime_config_hash`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_mnp_versions' AND COLUMN_NAME='runtime_config_version')=0,
  'ALTER TABLE `la_wechat_mnp_versions` ADD COLUMN `runtime_config_version` varchar(64) NOT NULL DEFAULT '''' AFTER `api_base_url`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_credentials' AND COLUMN_NAME='filing_json')=0,
  'ALTER TABLE `la_wechat_credentials` ADD COLUMN `filing_json` longtext NULL AFTER `settings_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
