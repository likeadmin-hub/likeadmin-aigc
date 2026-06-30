CREATE TABLE IF NOT EXISTS `la_aigc_person_replacement_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_mode` varchar(30) NOT NULL DEFAULT 'standard',
  `default_face_count` tinyint unsigned NOT NULL DEFAULT 1,
  `price_matrix` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作替换配置';

CREATE TABLE IF NOT EXISTS `la_aigc_person_replacement_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `reference_images` text,
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `video_url_snapshot` varchar(1000) NOT NULL DEFAULT '',
  `prompt` varchar(2000) NOT NULL DEFAULT '',
  `mode` varchar(30) NOT NULL DEFAULT 'standard',
  `face_count` tinyint unsigned NOT NULL DEFAULT 1,
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `request_snapshot` text,
  `upstream_usage` text,
  `provider_response` text,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_provider_task` (`provider_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_mode` (`tenant_id`,`mode`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作替换任务';

CREATE TABLE IF NOT EXISTS `la_aigc_person_replacement_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `result_json` text,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task` (`tenant_id`,`task_id`,`delete_time`),
  KEY `idx_provider_task` (`provider_task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作替换结果';

DELETE FROM `la_app` WHERE `code`='aigc_person_replacement';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_person_replacement','动作替换','resource/image/common/menu_generator.png','上传参考人物图片和输入视频，将视频动作替换到人物形象生成新视频。','aigc','','tenant,pc',0,0,0,846,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`cover`=VALUES(`cover`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_version` WHERE `app_code`='aigc_person_replacement';
INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_person_replacement','1.0.0','>=1.0.0','local','{"code":"aigc_person_replacement","name":"动作替换","version":"1.0.0","require_core":">=1.0.0","description":"上传参考人物图片和输入视频，将视频动作替换到人物形象生成新视频。","changelog":"1. 新增动作替换工具。\\n2. 支持 PC 端上传人物图和输入视频生成动作替换视频。\\n3. 支持租户配置模式售价和查看任务记录。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":0,"expire_policy":"allow","sort":846,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_person_replacement","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_person_replacement_admin","name":"动作替换","path":"/app/aigc_person_replacement","icon":"el-icon-VideoCamera","sort":90,"status":1},{"terminal":"pc","entry_key":"aigc_person_replacement","name":"动作替换","path":"/ai/tools/aigc_person_replacement","icon":"resource/image/common/menu_generator.png","sort":82,"status":1}]}','1. 新增动作替换工具。
2. 支持 PC 端上传人物图和输入视频生成动作替换视频。
3. 支持租户配置模式售价和查看任务记录。',1,UNIX_TIMESTAMP());

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_person_replacement';
SET @app_frontend_entry_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_app_frontend_entry');
SET @app_frontend_entry_create_time_sql = (SELECT IF(@app_frontend_entry_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_app_frontend_entry` ADD COLUMN `create_time` int unsigned NOT NULL DEFAULT 0 AFTER `meta`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_app_frontend_entry' AND COLUMN_NAME = 'create_time');
PREPARE app_frontend_entry_create_time_stmt FROM @app_frontend_entry_create_time_sql;
EXECUTE app_frontend_entry_create_time_stmt;
DEALLOCATE PREPARE app_frontend_entry_create_time_stmt;

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`)
VALUES
('aigc_person_replacement','tenant','aigc_person_replacement_admin','动作替换','/app/aigc_person_replacement','el-icon-VideoCamera',90,1,'{}',UNIX_TIMESTAMP()),
('aigc_person_replacement','pc','aigc_person_replacement','动作替换','/ai/tools/aigc_person_replacement','resource/image/common/menu_generator.png',82,1,'{}',UNIX_TIMESTAMP());

INSERT INTO `la_app_plan` (`app_code`,`name`,`duration_months`,`open_points`,`renew_points`,`status`,`sort`,`create_time`,`update_time`)
SELECT 'aigc_person_replacement','一年套餐',12,0.00,0.00,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_app_plan` WHERE `app_code`='aigc_person_replacement'
);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT t.`id`,'aigc_person_replacement','1.0.0','paid','on','enabled',4102415999,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_api` WHERE `app_code`='aigc_person_replacement';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_person_replacement','app.aigc_person_replacement.config/detail','GET','aigc_person_replacement:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.config/setup','POST','aigc_person_replacement:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/lists','GET','aigc_person_replacement:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/detail','GET','aigc_person_replacement:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/retry','POST','aigc_person_replacement:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/delete','POST','aigc_person_replacement:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.config/detail','GET','aigc_person_replacement:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.generate/estimate','POST','aigc_person_replacement:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.generate/index','POST','aigc_person_replacement:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/lists','GET','aigc_person_replacement:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/detail','GET','aigc_person_replacement:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.task/delete','POST','aigc_person_replacement:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_person_replacement','app.aigc_person_replacement.result/delete','POST','aigc_person_replacement:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_person_replacement' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','动作替换','el-icon-VideoCamera',82,'','aigc-person-replacement','','','',0,1,0,'aigc_person_replacement','app','aigc_person_replacement',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_person_replacement.config/detail','config','apps/aigc_person_replacement/config','','',0,1,0,'aigc_person_replacement','app','aigc_person_replacement_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_person_replacement' AND root.`source_menu_key`='aigc_person_replacement';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_person_replacement.task/lists','task','apps/aigc_person_replacement/task','','',0,1,0,'aigc_person_replacement','app','aigc_person_replacement_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_person_replacement' AND root.`source_menu_key`='aigc_person_replacement';
