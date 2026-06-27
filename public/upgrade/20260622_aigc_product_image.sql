CREATE TABLE IF NOT EXISTS `la_aigc_product_image_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_size_key` varchar(60) NOT NULL DEFAULT '1:1',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_scene_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图场景分类';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_scene_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `category_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) NOT NULL DEFAULT '',
  `image` varchar(500) NOT NULL DEFAULT '',
  `prompt` text,
  `vip` tinyint NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_category` (`tenant_id`,`category_id`,`status`,`sort`),
  KEY `idx_tenant_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图场景模板';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `product_image` varchar(500) NOT NULL DEFAULT '',
  `scene_mode` varchar(30) NOT NULL DEFAULT 'template',
  `template_id` int unsigned NOT NULL DEFAULT 0,
  `custom_scene_image` varchar(500) NOT NULL DEFAULT '',
  `size_key` varchar(80) NOT NULL DEFAULT '1:1',
  `width` int unsigned NOT NULL DEFAULT 800,
  `height` int unsigned NOT NULL DEFAULT 800,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
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
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_image_result` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品图结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_product_image';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_image','AI商品图','resource/image/common/menu_generator.png','面向电商商品图生成的 AI 工具，复用 AIGC 生图通道并支持独立售价、场景分类和场景模板。','aigc','','tenant,pc',0,0,1,852,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_product_image','1.0.0','>=1.0.0','local','{"code":"aigc_product_image","name":"AI商品图","version":"1.0.0","require_core":">=1.0.0","description":"面向电商商品图生成的 AI 工具，复用 AIGC 生图通道并支持独立售价、场景分类和场景模板。","changelog":"1. 新增 AI 商品图生成工具。\n2. 支持租户维护场景分类和场景模板。\n3. 支持 PC 端上传商品图、自定义场景和独立点数计费。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":852,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_product_image","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_product_image_admin","name":"AI商品图","path":"/app/aigc_product_image","icon":"el-icon-Picture","sort":94,"status":1},{"terminal":"pc","entry_key":"aigc_product_image","name":"AI商品图","path":"/ai/tools/aigc_product_image","icon":"resource/image/common/menu_generator.png","sort":89,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"商品图生成"}]}','1. 新增 AI 商品图生成工具。
2. 支持租户维护场景分类和场景模板。
3. 支持 PC 端上传商品图、自定义场景和独立点数计费。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_product_image','tenant','aigc_product_image_admin','AI商品图','/app/aigc_product_image','el-icon-Picture',94,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_image','pc','aigc_product_image','AI商品图','/ai/tools/aigc_product_image','resource/image/common/menu_generator.png',89,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_product_image';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_image','app.aigc_product_image.config/detail','GET','aigc_product_image:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.config/setup','POST','aigc_product_image:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_category/lists','GET','aigc_product_image:scene_category:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_category/save','POST','aigc_product_image:scene_category:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_category/status','POST','aigc_product_image:scene_category:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_category/delete','POST','aigc_product_image:scene_category:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/lists','GET','aigc_product_image:scene_template:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/detail','GET','aigc_product_image:scene_template:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/save','POST','aigc_product_image:scene_template:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/status','POST','aigc_product_image:scene_template:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/delete','POST','aigc_product_image:scene_template:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/lists','GET','aigc_product_image:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/detail','GET','aigc_product_image:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/retry','POST','aigc_product_image:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/delete','POST','aigc_product_image:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.tenant/stat','GET','aigc_product_image:tenant_usage','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.tenant/lists','GET','aigc_product_image:tenant_usage','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.config/dependencies','GET','aigc_product_image:dependencies:platform','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.config/detail','GET','aigc_product_image:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_category/lists','GET','aigc_product_image:scene_category:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/lists','GET','aigc_product_image:scene_template:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.scene_template/detail','GET','aigc_product_image:scene_template_detail:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.generate/estimate','POST','aigc_product_image:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.generate/index','POST','aigc_product_image:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/lists','GET','aigc_product_image:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/detail','GET','aigc_product_image:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.task/delete','POST','aigc_product_image:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.result/lists','GET','aigc_product_image:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_image','app.aigc_product_image.result/delete','POST','aigc_product_image:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_system_menu`
WHERE `app_code`='aigc_product_image'
  AND `source`='app';

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(0,'M','AI商品图','el-icon-Picture',83,'','aigc-product-image','','','',0,1,0,'aigc_product_image','app','aigc_product_image_platform',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`id`,'C','租户用量','',0,'app.aigc_product_image.tenant/stat','tenant-usage','apps/aigc_product_image/tenant-usage','','',0,1,0,'aigc_product_image','app','aigc_product_image_platform_tenant_usage',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image_platform';

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`id`,'C','依赖状态','',0,'app.aigc_product_image.config/dependencies','dependencies','apps/aigc_product_image/dependencies','','',0,1,0,'aigc_product_image','app','aigc_product_image_platform_dependency',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image_platform';

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_product_image'
  AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','AI商品图','el-icon-Picture',90,'','aigc-product-image','','','',0,1,0,'aigc_product_image','app','aigc_product_image',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_image.config/detail','config','apps/aigc_product_image/config','','',0,1,0,'aigc_product_image','app','aigc_product_image_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','场景分类','',30,'app.aigc_product_image.scene_category/lists','category','apps/aigc_product_image/category','','',0,1,0,'aigc_product_image','app','aigc_product_image_category',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','场景模板','',20,'app.aigc_product_image.scene_template/lists','template','apps/aigc_product_image/template','','',0,1,0,'aigc_product_image','app','aigc_product_image_template',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_image.task/lists','task','apps/aigc_product_image/task','','',0,1,0,'aigc_product_image','app','aigc_product_image_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_image' AND root.`source_menu_key`='aigc_product_image';
