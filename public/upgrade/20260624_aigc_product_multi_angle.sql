-- Built-in app: 商品多角度
-- ----------------------------
CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_view` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度视角选项';

CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(80) NOT NULL DEFAULT '',
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `view_codes` text,
  `view_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `user_prompt` varchar(1000) NOT NULL DEFAULT '',
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
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
  KEY `idx_batch` (`tenant_id`,`batch_no`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_multi_angle_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `view_code` varchar(80) NOT NULL DEFAULT '',
  `view_name` varchar(100) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_task_view` (`tenant_id`,`task_id`,`view_code`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品多角度结果';


DELETE FROM `la_app` WHERE `code`='aigc_product_multi_angle';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_multi_angle','商品多角度','resource/image/common/menu_generator.png','面向商品图的 AI 商品多角度工具，支持单图上传、多选视角和租户独立按视角定价。','aigc','','tenant,pc',0,0,1,848,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`cover`=VALUES(`cover`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_version` WHERE `app_code`='aigc_product_multi_angle';
INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_product_multi_angle','1.0.0','>=1.0.0','local','{\n  "code": "aigc_product_multi_angle",\n  "name": "商品多角度",\n  "version": "1.0.0",\n  "require_core": ">=1.0.0",\n  "description": "面向商品图的 AI 商品多角度工具，支持单图上传、多选视角和租户独立按视角定价。",\n  "changelog": "1. 新增商品多角度工具。\\n2. 支持租户配置视角选项和按视角售价。\\n3. 支持 PC 端单图生成多视角作品。",\n  "icon": "resource/image/common/menu_generator.png",\n  "category": "aigc",\n  "cover": "",\n  "is_builtin": 1,\n  "expire_policy": "allow",\n  "sort": 849,\n  "frontends": ["tenant", "pc"],\n  "api_prefix": "/app/aigc_product_multi_angle",\n  "menus": "menus/tenant.json",\n  "permissions": "permissions/tenant.json",\n  "migrations": "migrations",\n  "frontend_entries": [\n    { "terminal": "tenant", "entry_key": "aigc_product_multi_angle_admin", "name": "商品多角度", "path": "/app/aigc_product_multi_angle", "icon": "el-icon-Picture", "sort": 91, "status": 1 },\n    { "terminal": "pc", "entry_key": "aigc_product_multi_angle", "name": "商品多角度", "path": "/ai/tools/aigc_product_multi_angle", "icon": "resource/image/common/menu_generator.png", "sort": 84, "status": 1 }\n  ],\n  "dependencies": [\n    { "app_code": "aigc_image", "name": "AIGC生图", "required_for": "商品多角度生成" }\n  ]\n}\n','1. 新增商品多角度工具。
2. 支持租户配置视角选项和按视角售价。
3. 支持 PC 端单图生成多视角作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_product_multi_angle';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`)
VALUES
('aigc_product_multi_angle','tenant','aigc_product_multi_angle_admin','商品多角度','/app/aigc_product_multi_angle','el-icon-Picture',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_multi_angle','pc','aigc_product_multi_angle','商品多角度','/ai/tools/aigc_product_multi_angle','resource/image/common/menu_generator.png',84,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_product_multi_angle';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_multi_angle','app.aigc_product_multi_angle.config/detail','GET','aigc_product_multi_angle:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.config/setup','POST','aigc_product_multi_angle:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/lists','GET','aigc_product_multi_angle:view:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/save','POST','aigc_product_multi_angle:view:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/status','POST','aigc_product_multi_angle:view:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/delete','POST','aigc_product_multi_angle:view:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.price/detail','GET','aigc_product_multi_angle:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.price/setup','POST','aigc_product_multi_angle:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/lists','GET','aigc_product_multi_angle:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/detail','GET','aigc_product_multi_angle:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/retry','POST','aigc_product_multi_angle:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/delete','POST','aigc_product_multi_angle:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.config/detail','GET','aigc_product_multi_angle:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.view/lists','GET','aigc_product_multi_angle:view:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.generate/estimate','POST','aigc_product_multi_angle:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.generate/index','POST','aigc_product_multi_angle:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/lists','GET','aigc_product_multi_angle:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/detail','GET','aigc_product_multi_angle:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.task/delete','POST','aigc_product_multi_angle:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.result/lists','GET','aigc_product_multi_angle:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_multi_angle','app.aigc_product_multi_angle.result/delete','POST','aigc_product_multi_angle:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_multi_angle' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','商品多角度','el-icon-Picture',84,'','aigc-product-multi-angle','','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_multi_angle.config/detail','config','apps/aigc_product_multi_angle/config','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','视角选项','',35,'app.aigc_product_multi_angle.view/lists','view','apps/aigc_product_multi_angle/view','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_view',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_product_multi_angle.price/detail','price','apps/aigc_product_multi_angle/price','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_multi_angle.task/lists','task','apps/aigc_product_multi_angle/task','','',0,1,0,'aigc_product_multi_angle','app','aigc_product_multi_angle_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_product_multi_angle' AND root.`source_menu_key`='aigc_product_multi_angle';
