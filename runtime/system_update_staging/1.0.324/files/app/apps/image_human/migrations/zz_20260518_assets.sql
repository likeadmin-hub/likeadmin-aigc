CREATE TABLE IF NOT EXISTS `la_image_human_avatar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方形象',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `scene` varchar(50) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `media_uri` varchar(500) NOT NULL DEFAULT '',
  `media_type` varchar(20) NOT NULL DEFAULT 'image',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_asset_id` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`source`,`delete_time`),
  KEY `idx_provider` (`tenant_id`,`provider`,`provider_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人图片形象';

SET @image_human_task_table = CONCAT(DATABASE(), '.la_image_human_task');
SET @image_human_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_image_human_task` ADD COLUMN `avatar_id` int unsigned NOT NULL DEFAULT 0 AFTER `user_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_image_human_task' AND COLUMN_NAME = 'avatar_id');
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

SET @image_human_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_image_human_task` ADD COLUMN `voice_id` int unsigned NOT NULL DEFAULT 0 AFTER `avatar_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_image_human_task' AND COLUMN_NAME = 'voice_id');
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

SET @image_human_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_image_human_result` ADD COLUMN `avatar_id` int unsigned NOT NULL DEFAULT 0 AFTER `user_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_image_human_result' AND COLUMN_NAME = 'avatar_id');
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

SET @image_human_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_image_human_result` ADD COLUMN `voice_id` int unsigned NOT NULL DEFAULT 0 AFTER `avatar_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_image_human_result' AND COLUMN_NAME = 'voice_id');
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;
