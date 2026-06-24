CREATE TABLE IF NOT EXISTS `la_aigc_image_translate_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `default_target_language` varchar(30) NOT NULL DEFAULT 'en',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片翻译配置';

CREATE TABLE IF NOT EXISTS `la_aigc_image_translate_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `source_language` varchar(30) NOT NULL DEFAULT 'auto',
  `source_language_label` varchar(80) NOT NULL DEFAULT '',
  `target_language` varchar(30) NOT NULL DEFAULT 'en',
  `target_language_label` varchar(80) NOT NULL DEFAULT '',
  `price_package_code` varchar(80) NOT NULL DEFAULT '',
  `price_package_name` varchar(100) NOT NULL DEFAULT '',
  `price_package_snapshot` text,
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
  KEY `idx_language` (`tenant_id`,`source_language`,`target_language`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片翻译任务';

CREATE TABLE IF NOT EXISTS `la_aigc_image_translate_result` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片翻译结果';


DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_image_translate';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_image_translate','图片翻译','resource/image/common/menu_generator.png','面向商品图、海报和素材图的 AI 图片翻译工具，复用 AIGC 生图通道并支持租户独立翻译质量定价。','aigc','','tenant,pc',0,0,1,850,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_image_translate','1.0.0','>=1.0.0','local','{\n  "code": "aigc_image_translate",\n  "name": "图片翻译",\n  "version": "1.0.0",\n  "require_core": ">=1.0.0",\n  "description": "面向商品图、人物图和素材图的 AI 图片翻译工具，复用 AIGC 生图通道并支持租户独立翻译质量定价。",\n  "changelog": "1. 新增图片翻译工具。\n2. 支持租户配置翻译质量售价。\n3. 支持 PC 端上传图片生成翻译作品。",\n  "icon": "resource/image/common/menu_generator.png",\n  "category": "aigc",\n  "cover": "",\n  "is_builtin": 1,\n  "expire_policy": "allow",\n  "sort": 852,\n  "frontends": [\n    "tenant",\n    "pc"\n  ],\n  "api_prefix": "/app/aigc_image_translate",\n  "menus": "menus/tenant.json",\n  "permissions": "permissions/tenant.json",\n  "migrations": "migrations",\n  "frontend_entries": [\n    {\n      "terminal": "tenant",\n      "entry_key": "aigc_image_translate_admin",\n      "name": "图片翻译",\n      "path": "/app/aigc_image_translate",\n      "icon": "el-icon-Picture",\n      "sort": 92,\n      "status": 1\n    },\n    {\n      "terminal": "pc",\n      "entry_key": "aigc_image_translate",\n      "name": "图片翻译",\n      "path": "/ai/tools/aigc_image_translate",\n      "icon": "resource/image/common/menu_generator.png",\n      "sort": 87,\n      "status": 1\n    }\n  ],\n  "dependencies": [\n    {\n      "app_code": "aigc_image",\n      "name": "AIGC生图",\n      "required_for": "图片翻译生成"\n    }\n  ]\n}\n','1. 新增图片翻译工具。\n2. 支持租户配置翻译质量售价。\n3. 支持 PC 端上传图片生成翻译作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

DELETE FROM `la_app_frontend_entry`
WHERE `app_code`='aigc_image_translate';

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_image_translate','tenant','aigc_image_translate_admin','图片翻译','/app/aigc_image_translate','el-icon-Picture',92,1,'{}',UNIX_TIMESTAMP()),
('aigc_image_translate','pc','aigc_image_translate','图片翻译','/ai/tools/aigc_image_translate','resource/image/common/menu_generator.png',87,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_image_translate';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_image_translate','app.aigc_image_translate.config/detail','GET','aigc_image_translate:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.config/setup','POST','aigc_image_translate:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.price/detail','GET','aigc_image_translate:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.price/setup','POST','aigc_image_translate:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/lists','GET','aigc_image_translate:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/detail','GET','aigc_image_translate:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/retry','POST','aigc_image_translate:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/delete','POST','aigc_image_translate:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.config/detail','GET','aigc_image_translate:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.generate/estimate','POST','aigc_image_translate:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.generate/index','POST','aigc_image_translate:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/lists','GET','aigc_image_translate:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/detail','GET','aigc_image_translate:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.task/delete','POST','aigc_image_translate:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.result/lists','GET','aigc_image_translate:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_image_translate','app.aigc_image_translate.result/delete','POST','aigc_image_translate:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_image_translate','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant`
WHERE EXISTS (
  SELECT 1 FROM `la_app`
  WHERE `code`='aigc_image_translate' AND `status`='installed'
)
UNION ALL
SELECT 0,'aigc_image_translate','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE EXISTS (
  SELECT 1 FROM `la_app`
  WHERE `code`='aigc_image_translate' AND `status`='installed'
)
ON DUPLICATE KEY UPDATE
  `version`=VALUES(`version`),
  `buy_status`=VALUES(`buy_status`),
  `shelf_status`=VALUES(`shelf_status`),
  `enable_status`=VALUES(`enable_status`),
  `expire_time`=VALUES(`expire_time`),
  `update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_image_translate'
  AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','图片翻译','el-icon-Picture',86,'','aigc-image-translate','','','',0,1,0,'aigc_image_translate','app','aigc_image_translate',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_image_translate.config/detail','config','apps/aigc_image_translate/config','','',0,1,0,'aigc_image_translate','app','aigc_image_translate_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_image_translate' AND root.`source_menu_key`='aigc_image_translate';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_image_translate.price/detail','price','apps/aigc_image_translate/price','','',0,1,0,'aigc_image_translate','app','aigc_image_translate_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_image_translate' AND root.`source_menu_key`='aigc_image_translate';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_image_translate.task/lists','task','apps/aigc_image_translate/task','','',0,1,0,'aigc_image_translate','app','aigc_image_translate_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_image_translate' AND root.`source_menu_key`='aigc_image_translate';
