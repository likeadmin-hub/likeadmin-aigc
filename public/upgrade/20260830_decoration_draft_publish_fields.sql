-- 装修草稿/正式数据隔离：为旧安装补齐字段并初始化快照。
-- 本脚本只增加字段、填充空快照，不覆盖已有装修内容，可重复执行。
SET NAMES utf8mb4;
SET @db_name := DATABASE();

-- 旧版本可能已经创建过模板表但缺少发布设置字段。
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template' AND COLUMN_NAME='publish_status') = 0,
    'ALTER TABLE `la_decorate_template` ADD COLUMN `publish_status` varchar(30) NOT NULL DEFAULT ''draft'' COMMENT ''draft/published''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'published_pages';
SET @field_sql := 'ALTER TABLE `la_decorate_template` ADD COLUMN `published_pages` longtext COMMENT ''正式页面结构''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'published_history';
SET @field_sql := 'ALTER TABLE `la_decorate_template` ADD COLUMN `published_history` longtext COMMENT ''发布快照历史''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template' AND COLUMN_NAME='draft_settings') = 0,
    'ALTER TABLE `la_decorate_template` ADD COLUMN `draft_settings` longtext COMMENT ''草稿设置''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template') > 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template' AND COLUMN_NAME='published_settings') = 0,
    'ALTER TABLE `la_decorate_template` ADD COLUMN `published_settings` longtext COMMENT ''发布设置''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 页面字段按依赖顺序逐列添加，避免旧库一次 ALTER 失败后无法重试。
SET @field_name := 'template_id';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `template_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''模板ID''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'terminal';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `terminal` varchar(20) NOT NULL DEFAULT ''mobile'' COMMENT ''终端 mobile/pc''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'channel';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `channel` varchar(20) NOT NULL DEFAULT ''common'' COMMENT ''渠道 common/h5/mp_weixin''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'page_code';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `page_code` varchar(64) NOT NULL DEFAULT '''' COMMENT ''页面标识''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'page_type';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `page_type` varchar(30) NOT NULL DEFAULT ''custom'' COMMENT ''页面类型''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'route_path';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `route_path` varchar(255) NOT NULL DEFAULT '''' COMMENT ''页面路径''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'is_home';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `is_home` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''是否首页''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'is_system';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `is_system` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''是否系统页面''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'status';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT ''状态''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'sort';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `sort` int NOT NULL DEFAULT 0 COMMENT ''排序''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'draft_data';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `draft_data` longtext COMMENT ''草稿数据''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'draft_meta';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `draft_meta` longtext COMMENT ''草稿页面设置''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'published_data';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `published_data` longtext COMMENT ''发布数据''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'published_meta';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `published_meta` longtext COMMENT ''发布页面设置''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @field_name := 'draft_deleted';
SET @field_sql := 'ALTER TABLE `la_decorate_page` ADD COLUMN `draft_deleted` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''草稿待删除''';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page' AND COLUMN_NAME=@field_name)=0, @field_sql, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 仅填充空快照：已有草稿或正式数据绝不覆盖。
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_page') > 0,
    'UPDATE `la_decorate_page` SET `draft_data`=COALESCE(NULLIF(`draft_data`,''''),`data`), `draft_meta`=COALESCE(NULLIF(`draft_meta`,''''),`meta`), `published_data`=COALESCE(NULLIF(`published_data`,''''),`data`,`draft_data`), `published_meta`=COALESCE(NULLIF(`published_meta`,''''),`meta`,`draft_meta`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_decorate_template') > 0,
    'UPDATE `la_decorate_template` SET `draft_settings`=COALESCE(NULLIF(`draft_settings`,''''),`published_settings`), `published_settings`=COALESCE(NULLIF(`published_settings`,''''),`draft_settings`), `publish_status`=COALESCE(NULLIF(`publish_status`,''''),''draft'')',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
