CREATE TABLE IF NOT EXISTS `la_aigc_fitting_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_mode` varchar(30) NOT NULL DEFAULT 'single',
  `default_upload_category` varchar(30) NOT NULL DEFAULT 'full',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI试衣配置';

CREATE TABLE IF NOT EXISTS `la_aigc_fitting_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `mode` varchar(30) NOT NULL DEFAULT 'single',
  `upload_category` varchar(30) NOT NULL DEFAULT 'full',
  `model_filter` varchar(80) NOT NULL DEFAULT '',
  `clothes_filter` varchar(80) NOT NULL DEFAULT '',
  `pose_filter` varchar(80) NOT NULL DEFAULT '',
  `garment_images` text,
  `model_images` text,
  `selected_preset_ids` text,
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
  KEY `idx_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI试衣任务';

SET @aigc_fitting_task_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_fitting_task');
SET @aigc_fitting_task_sql = (SELECT IF(@aigc_fitting_task_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_fitting_task` ADD COLUMN `image_task_ids` text AFTER `image_task_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_fitting_task' AND COLUMN_NAME = 'image_task_ids');
PREPARE stmt FROM @aigc_fitting_task_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_fitting';

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_fitting'
  AND `api_path` IN ('app.aigc_fitting.garment/detail','app.aigc_fitting.garment/setup');

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_fitting','AI试衣','resource/image/common/menu_generator.png','面向服装效果预览的 AI 试衣应用，复用 AIGC 生图通道并支持独立用户售价。','aigc','','tenant,pc',0,0,1,855,'1.0.1','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_fitting','1.0.1','>=1.0.0','local','{"code":"aigc_fitting","name":"AI试衣","version":"1.0.1","require_core":">=1.0.0","description":"面向服装效果预览的 AI 试衣应用，复用 AIGC 生图通道并支持独立用户售价。","changelog":"1. 新增 AI 试衣应用。\n2. 支持单图、组图和自定义模特三种试衣模式。\n3. 租户后台支持配置试衣价格、提示词、示例图和任务记录。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":855,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_fitting","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_fitting_admin","name":"AI试衣","path":"/app/aigc_fitting","icon":"el-icon-Camera","sort":95,"status":1},{"terminal":"pc","entry_key":"aigc_fitting","name":"AI试衣","path":"/ai/tools/aigc_fitting","icon":"resource/image/common/menu_generator.png","sort":91,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"图片生成"}]}','1. 新增 AI 试衣应用。
2. 支持单图、组图和自定义模特三种试衣模式。
3. 租户后台支持配置试衣价格、提示词、示例图和任务记录。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_fitting','tenant','aigc_fitting_admin','AI试衣','/app/aigc_fitting','el-icon-Camera',95,1,'{}',UNIX_TIMESTAMP()),
('aigc_fitting','pc','aigc_fitting','AI试衣','/ai/tools/aigc_fitting','resource/image/common/menu_generator.png',91,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_fitting','app.aigc_fitting.config/detail','GET','aigc_fitting:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.config/setup','POST','aigc_fitting:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.model/detail','GET','aigc_fitting:model:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.model/setup','POST','aigc_fitting:model:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/lists','GET','aigc_fitting:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/detail','GET','aigc_fitting:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/retry','POST','aigc_fitting:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/delete','POST','aigc_fitting:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.config/detail','GET','aigc_fitting:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.generate/estimate','POST','aigc_fitting:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.generate/index','POST','aigc_fitting:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/lists','GET','aigc_fitting:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/detail','GET','aigc_fitting:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.result/lists','GET','aigc_fitting:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_fitting'
  AND `source`='app'
  AND `source_menu_key` IN ('aigc_fitting','aigc_fitting_config','aigc_fitting_garment','aigc_fitting_model','aigc_fitting_task');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','AI试衣','el-icon-Camera',91,'','aigc-fitting','','','',0,1,0,'aigc_fitting','app','aigc_fitting',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',0,'app.aigc_fitting.config/detail','config','apps/aigc_fitting/config','','',0,1,0,'aigc_fitting','app','aigc_fitting_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_fitting' AND root.`source_menu_key`='aigc_fitting';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','模特管理','',0,'app.aigc_fitting.model/detail','model','apps/aigc_fitting/model','','',0,1,0,'aigc_fitting','app','aigc_fitting_model',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_fitting' AND root.`source_menu_key`='aigc_fitting';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',0,'app.aigc_fitting.task/lists','task','apps/aigc_fitting/task','','',0,1,0,'aigc_fitting','app','aigc_fitting_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_fitting' AND root.`source_menu_key`='aigc_fitting';
