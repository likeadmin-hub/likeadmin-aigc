CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `default_duration` int unsigned NOT NULL DEFAULT 0,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prompt_template` text,
  `negative_prompt` text,
  `price_matrix` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频配置';

SET @aigc_product_promo_video_config_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_product_promo_video_config` ADD COLUMN `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `default_duration`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_product_promo_video_config' AND COLUMN_NAME = 'unit_price');
PREPARE aigc_product_promo_video_config_stmt FROM @aigc_product_promo_video_config_sql;
EXECUTE aigc_product_promo_video_config_stmt;
DEALLOCATE PREPARE aigc_product_promo_video_config_stmt;

UPDATE `la_aigc_product_promo_video_config` SET `default_duration` = 0;

CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `cover_image` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `is_builtin` tinyint NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频类型';

CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `video_task_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `type_code` varchar(80) NOT NULL DEFAULT '',
  `type_name` varchar(100) NOT NULL DEFAULT '',
  `type_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `user_prompt` varchar(2000) NOT NULL DEFAULT '',
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_video_task` (`video_task_id`),
  KEY `idx_type` (`tenant_id`,`type_code`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_promo_video_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `video_task_id` int unsigned NOT NULL DEFAULT 0,
  `video_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `type_code` varchar(80) NOT NULL DEFAULT '',
  `type_name` varchar(100) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_video_result` (`tenant_id`,`video_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品宣传视频结果';

DELETE FROM `la_app` WHERE `code`='aigc_product_promo_video';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`is_system`,`is_default`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_promo_video','产品宣传视频','resource/image/common/menu_generator.png','面向电商产品传播的 AI 产品宣传视频工具，支持产品图生成视频、租户配置视频类型和按秒生成售价。','aigc','','tenant,pc',0,0,1,847,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`description`=VALUES(`description`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_product_promo_video';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`)
VALUES
('aigc_product_promo_video','tenant','aigc_product_promo_video_admin','产品宣传视频','/app/aigc_product_promo_video','el-icon-VideoCamera',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_promo_video','pc','aigc_product_promo_video','产品宣传视频','/ai/tools/aigc_product_promo_video','resource/image/common/menu_generator.png',83,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_product_promo_video';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_promo_video','app.aigc_product_promo_video.config/detail','GET','aigc_product_promo_video:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.config/setup','POST','aigc_product_promo_video:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/lists','GET','aigc_product_promo_video:type:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/save','POST','aigc_product_promo_video:type:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/status','POST','aigc_product_promo_video:type:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/delete','POST','aigc_product_promo_video:type:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/lists','GET','aigc_product_promo_video:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/detail','GET','aigc_product_promo_video:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/retry','POST','aigc_product_promo_video:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/delete','POST','aigc_product_promo_video:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.config/detail','GET','aigc_product_promo_video:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.type/lists','GET','aigc_product_promo_video:type:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.prompt/write','POST','aigc_product_promo_video:prompt:write','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.prompt/optimize','POST','aigc_product_promo_video:prompt:optimize','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.generate/estimate','POST','aigc_product_promo_video:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.generate/index','POST','aigc_product_promo_video:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/lists','GET','aigc_product_promo_video:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/detail','GET','aigc_product_promo_video:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.task/delete','POST','aigc_product_promo_video:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.result/lists','GET','aigc_product_promo_video:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_promo_video','app.aigc_product_promo_video.result/delete','POST','aigc_product_promo_video:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_promo_video' AND `source_menu_key`='aigc_product_promo_video_price';
DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_promo_video' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`delete_time`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','产品宣传视频','el-icon-VideoCamera',83,'','aigc-product-promo-video','','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`delete_time`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_promo_video.config/detail','config','apps/aigc_product_promo_video/config','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_promo_video' AND root.`source_menu_key`='aigc_product_promo_video';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`delete_time`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','视频类型','',35,'app.aigc_product_promo_video.type/lists','type','apps/aigc_product_promo_video/type','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video_type',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_promo_video' AND root.`source_menu_key`='aigc_product_promo_video';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`delete_time`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_promo_video.task/lists','task','apps/aigc_product_promo_video/task','','',0,1,0,'aigc_product_promo_video','app','aigc_product_promo_video_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_promo_video' AND root.`source_menu_key`='aigc_product_promo_video';
