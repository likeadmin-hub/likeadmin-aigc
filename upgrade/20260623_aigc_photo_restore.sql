CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复配置';

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(60) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复类型';

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `restore_type` varchar(60) NOT NULL DEFAULT '',
  `restore_type_name` varchar(100) NOT NULL DEFAULT '',
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
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
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_restore_type` (`tenant_id`,`restore_type`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复任务';

CREATE TABLE IF NOT EXISTS `la_aigc_photo_restore_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='老照片修复结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_photo_restore';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_photo_restore','老照片修复','resource/image/common/menu_generator.png','面向老照片修复和上色的 AI 工具，复用 AIGC 生图通道并支持独立模型规格售价。','aigc','','tenant,pc',0,0,1,851,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_photo_restore','1.0.0','>=1.0.0','local','{"code":"aigc_photo_restore","name":"老照片修复","version":"1.0.0","require_core":">=1.0.0","description":"面向老照片修复和上色的 AI 工具，复用 AIGC 生图通道并支持独立模型规格售价。","changelog":"1. 新增老照片修复工具。\n2. 支持租户配置修复类型和模型规格售价。\n3. 支持 PC 端上传老照片、选择修复类型和生成作品。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":851,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_photo_restore","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_photo_restore_admin","name":"老照片修复","path":"/app/aigc_photo_restore","icon":"el-icon-Picture","sort":93,"status":1},{"terminal":"pc","entry_key":"aigc_photo_restore","name":"老照片修复","path":"/ai/tools/aigc_photo_restore","icon":"resource/image/common/menu_generator.png","sort":88,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"照片修复生成"}]}','1. 新增老照片修复工具。
2. 支持租户配置修复类型和模型规格售价。
3. 支持 PC 端上传老照片、选择修复类型和生成作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_photo_restore','tenant','aigc_photo_restore_admin','老照片修复','/app/aigc_photo_restore','el-icon-Picture',93,1,'{}',UNIX_TIMESTAMP()),
('aigc_photo_restore','pc','aigc_photo_restore','老照片修复','/ai/tools/aigc_photo_restore','resource/image/common/menu_generator.png',88,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_photo_restore';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_photo_restore','app.aigc_photo_restore.config/detail','GET','aigc_photo_restore:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.config/setup','POST','aigc_photo_restore:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/lists','GET','aigc_photo_restore:restore_type:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/save','POST','aigc_photo_restore:restore_type:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/status','POST','aigc_photo_restore:restore_type:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.price/detail','GET','aigc_photo_restore:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.price/setup','POST','aigc_photo_restore:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/lists','GET','aigc_photo_restore:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/detail','GET','aigc_photo_restore:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/retry','POST','aigc_photo_restore:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/delete','POST','aigc_photo_restore:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.config/detail','GET','aigc_photo_restore:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.restore_type/lists','GET','aigc_photo_restore:restore_type:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.generate/estimate','POST','aigc_photo_restore:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.generate/index','POST','aigc_photo_restore:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/lists','GET','aigc_photo_restore:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/detail','GET','aigc_photo_restore:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.task/delete','POST','aigc_photo_restore:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.result/lists','GET','aigc_photo_restore:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_photo_restore','app.aigc_photo_restore.result/delete','POST','aigc_photo_restore:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_photo_restore'
  AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','老照片修复','el-icon-Picture',89,'','aigc-photo-restore','','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_photo_restore.config/detail','config','apps/aigc_photo_restore/config','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','修复类型','',30,'app.aigc_photo_restore.restore_type/lists','restore-type','apps/aigc_photo_restore/restore-type','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_type',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',20,'app.aigc_photo_restore.price/detail','price','apps/aigc_photo_restore/price','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_photo_restore.task/lists','task','apps/aigc_photo_restore/task','','',0,1,0,'aigc_photo_restore','app','aigc_photo_restore_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_photo_restore' AND root.`source_menu_key`='aigc_photo_restore';
