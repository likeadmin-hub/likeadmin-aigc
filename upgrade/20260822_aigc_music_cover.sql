CREATE TABLE IF NOT EXISTS `la_aigc_music_asset` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `asset_type` varchar(30) NOT NULL DEFAULT 'reference_audio',
  `source_action` varchar(50) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(500) NOT NULL DEFAULT '',
  `url` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(100) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `checksum` varchar(80) NOT NULL DEFAULT '',
  `auth_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_asset_type` (`tenant_id`,`asset_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐素材';

CREATE TABLE IF NOT EXISTS `la_aigc_music_cover_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='音乐翻唱配置';

CREATE TABLE IF NOT EXISTS `la_aigc_music_cover_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(160) NOT NULL DEFAULT '',
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `source_asset_id` int unsigned NOT NULL DEFAULT 0,
  `source_uri` varchar(500) NOT NULL DEFAULT '',
  `source_url` varchar(1000) NOT NULL DEFAULT '',
  `reference_asset_id` int unsigned NOT NULL DEFAULT 0,
  `reference_uri` varchar(500) NOT NULL DEFAULT '',
  `reference_url` varchar(1000) NOT NULL DEFAULT '',
  `request_snapshot` text,
  `title` varchar(255) NOT NULL DEFAULT '音乐翻唱',
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_tenant_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_user_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_user` (`tenant_id`,`user_id`), KEY `idx_consumption` (`consumption_id`), KEY `idx_status` (`tenant_id`,`status`), KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='音乐翻唱任务';

CREATE TABLE IF NOT EXISTS `la_aigc_music_cover_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `audio_uri` varchar(500) NOT NULL DEFAULT '',
  `audio_url` varchar(1000) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(100) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_task_audio` (`tenant_id`,`task_id`,`audio_uri`), KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='音乐翻唱结果';

SET @music_cover_ref_asset_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_music_cover_task` ADD COLUMN `reference_asset_id` int unsigned NOT NULL DEFAULT 0 AFTER `source_url`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_music_cover_task' AND COLUMN_NAME = 'reference_asset_id');
PREPARE music_cover_ref_asset_stmt FROM @music_cover_ref_asset_sql;
EXECUTE music_cover_ref_asset_stmt;
DEALLOCATE PREPARE music_cover_ref_asset_stmt;

SET @music_cover_ref_uri_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_music_cover_task` ADD COLUMN `reference_uri` varchar(500) NOT NULL DEFAULT '''' AFTER `reference_asset_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_music_cover_task' AND COLUMN_NAME = 'reference_uri');
PREPARE music_cover_ref_uri_stmt FROM @music_cover_ref_uri_sql;
EXECUTE music_cover_ref_uri_stmt;
DEALLOCATE PREPARE music_cover_ref_uri_stmt;

SET @music_cover_ref_url_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_music_cover_task` ADD COLUMN `reference_url` varchar(1000) NOT NULL DEFAULT '''' AFTER `reference_uri`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_music_cover_task' AND COLUMN_NAME = 'reference_url');
PREPARE music_cover_ref_url_stmt FROM @music_cover_ref_url_sql;
EXECUTE music_cover_ref_url_stmt;
DEALLOCATE PREPARE music_cover_ref_url_stmt;

DELETE FROM `la_app` WHERE `code` = 'aigc_music_cover';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_music_cover','音乐翻唱','resource/image/common/menu_generator.png','上传原始歌曲和目标音色参考音频，生成新的演唱版本并支持试听与下载。','aigc','','tenant,pc',0,0,0,59,'1.0.2','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_app_frontend_entry` WHERE `app_code` = 'aigc_music_cover';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`) VALUES
('aigc_music_cover','tenant','aigc_music_cover_admin','音乐翻唱','/app/aigc_music_cover','el-icon-Microphone',88,1,'{}',UNIX_TIMESTAMP()),
('aigc_music_cover','pc','aigc_music_cover','音乐翻唱','/ai/tools/aigc_music_cover','resource/image/common/menu_generator.png',82,1,'{}',UNIX_TIMESTAMP());

INSERT INTO `la_app_plan` (`app_code`,`name`,`duration_months`,`open_points`,`renew_points`,`status`,`sort`,`create_time`,`update_time`)
SELECT 'aigc_music_cover','一年套餐',12,0.00,0.00,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_plan` WHERE `app_code`='aigc_music_cover');

DELETE FROM `la_app_api` WHERE `app_code` = 'aigc_music_cover';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES
('aigc_music_cover','app.aigc_music_cover.config/detail','GET','aigc_music_cover:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.config/setup','POST','aigc_music_cover:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.asset/upload_audio','POST','aigc_music_cover:asset:upload_audio:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.generate/estimate','POST','aigc_music_cover:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.generate/index','POST','aigc_music_cover:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.task/lists','GET','aigc_music_cover:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.task/detail','GET','aigc_music_cover:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.task/retry','POST','aigc_music_cover:task:retry:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.task/delete','POST','aigc_music_cover:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.result/lists','GET','aigc_music_cover:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.result/delete','POST','aigc_music_cover:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_music_cover','app.aigc_music_cover.config/detail','GET','aigc_music_cover:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
