-- Built-in app: AI商品套图
CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_config` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_module` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图模块选项';

CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(80) NOT NULL DEFAULT '',
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `product_images` text,
  `platform` varchar(80) NOT NULL DEFAULT '',
  `country` varchar(80) NOT NULL DEFAULT '',
  `language` varchar(80) NOT NULL DEFAULT '',
  `module_codes` text,
  `module_snapshot` text,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_product_suite_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `module_code` varchar(80) NOT NULL DEFAULT '',
  `module_name` varchar(100) NOT NULL DEFAULT '',
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
  KEY `idx_task_module` (`tenant_id`,`task_id`,`module_code`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI商品套图结果';

DELETE FROM `la_app` WHERE `code`='aigc_product_suite';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_product_suite','AI商品套图','resource/image/common/menu_generator.png','面向商品图的 AI 商品套图工具，支持最多3张商品图、多选模块和租户独立按模块定价。','aigc','','tenant,pc',0,0,1,849,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_frontend_entry` WHERE `app_code`='aigc_product_suite';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_product_suite','tenant','aigc_product_suite_admin','AI商品套图','/app/aigc_product_suite','el-icon-Picture',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_product_suite','pc','aigc_product_suite','AI商品套图','/ai/tools/aigc_product_suite','resource/image/common/menu_generator.png',84,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api` WHERE `app_code`='aigc_product_suite';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_product_suite','app.aigc_product_suite.config/detail','GET','aigc_product_suite:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.config/setup','POST','aigc_product_suite:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/lists','GET','aigc_product_suite:module:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/save','POST','aigc_product_suite:module:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/status','POST','aigc_product_suite:module:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/delete','POST','aigc_product_suite:module:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.price/detail','GET','aigc_product_suite:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.price/setup','POST','aigc_product_suite:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/lists','GET','aigc_product_suite:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/detail','GET','aigc_product_suite:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/retry','POST','aigc_product_suite:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/delete','POST','aigc_product_suite:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.config/detail','GET','aigc_product_suite:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.module/lists','GET','aigc_product_suite:module:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.prompt/optimize','POST','aigc_product_suite:prompt:optimize','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.generate/estimate','POST','aigc_product_suite:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.generate/index','POST','aigc_product_suite:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/lists','GET','aigc_product_suite:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/detail','GET','aigc_product_suite:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.task/delete','POST','aigc_product_suite:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.result/lists','GET','aigc_product_suite:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_product_suite','app.aigc_product_suite.result/delete','POST','aigc_product_suite:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_product_suite','1.0.0','>=1.0.0','local','{"code":"aigc_product_suite","name":"AI商品套图","version":"1.0.0","require_core":">=1.0.0","description":"面向商品图的 AI 商品套图工具，支持最多3张商品图、多选模块和租户独立按模块定价。","changelog":"1. 新增AI商品套图工具。\n2. 支持租户配置模块选项和按模块售价。\n3. 支持 PC 端单图生成多模块作品。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":849,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_product_suite","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_product_suite_admin","name":"AI商品套图","path":"/app/aigc_product_suite","icon":"el-icon-Picture","sort":91,"status":1},{"terminal":"pc","entry_key":"aigc_product_suite","name":"AI商品套图","path":"/ai/tools/aigc_product_suite","icon":"resource/image/common/menu_generator.png","sort":84,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"AI商品套图生成"},{"app_code":"aigc_llm","name":"AIGC对话","required_for":"核心卖点AI优化"}]}','1. 新增AI商品套图工具。
2. 支持租户配置模块选项和按模块售价。
3. 支持 PC 端单图生成多模块作品。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_product_suite','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant`
UNION ALL
SELECT 0,'aigc_product_suite','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `version`=VALUES(`version`),`buy_status`=VALUES(`buy_status`),`shelf_status`=VALUES(`shelf_status`),`enable_status`=VALUES(`enable_status`),`expire_time`=VALUES(`expire_time`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_product_suite' AND `source`='app';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','AI商品套图','el-icon-Picture',84,'','aigc-product-suite','','','',0,1,0,'aigc_product_suite','app','aigc_product_suite',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_product_suite.config/detail','config','apps/aigc_product_suite/config','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','模块选项','',35,'app.aigc_product_suite.module/lists','module','apps/aigc_product_suite/module','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_module',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_product_suite.price/detail','price','apps/aigc_product_suite/price','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_product_suite.task/lists','task','apps/aigc_product_suite/task','','',0,1,0,'aigc_product_suite','app','aigc_product_suite_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root WHERE root.`app_code`='aigc_product_suite' AND root.`source_menu_key`='aigc_product_suite';
