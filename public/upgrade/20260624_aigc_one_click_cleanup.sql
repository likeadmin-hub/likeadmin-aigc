CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_images` int unsigned NOT NULL DEFAULT 10,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除配置';

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_option` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除选项';

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(80) NOT NULL DEFAULT '',
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_images` text,
  `option_codes` text,
  `option_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除任务';

CREATE TABLE IF NOT EXISTS `la_aigc_one_click_cleanup_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `source_image` varchar(500) NOT NULL DEFAULT '',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='一键消除结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_one_click_cleanup';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_one_click_cleanup','一键消除','resource/image/common/menu_generator.png','面向商品图、素材图和内容图的 AI 一键消除工具，支持批量上传、多选消除项和租户独立单张定价。','aigc','','tenant,pc',0,0,1,849,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_app_frontend_entry`
WHERE `app_code`='aigc_one_click_cleanup';

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_one_click_cleanup','tenant','aigc_one_click_cleanup_admin','一键消除','/app/aigc_one_click_cleanup','el-icon-Picture',91,1,'{}',UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','pc','aigc_one_click_cleanup','一键消除','/ai/tools/aigc_one_click_cleanup','resource/image/common/menu_generator.png',84,1,'{}',UNIX_TIMESTAMP());

DELETE FROM `la_app_api`
WHERE `app_code`='aigc_one_click_cleanup';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.config/detail','GET','aigc_one_click_cleanup:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.config/setup','POST','aigc_one_click_cleanup:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/lists','GET','aigc_one_click_cleanup:option:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/save','POST','aigc_one_click_cleanup:option:save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/status','POST','aigc_one_click_cleanup:option:status','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/delete','POST','aigc_one_click_cleanup:option:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.price/detail','GET','aigc_one_click_cleanup:price:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.price/setup','POST','aigc_one_click_cleanup:price:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/lists','GET','aigc_one_click_cleanup:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/detail','GET','aigc_one_click_cleanup:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/retry','POST','aigc_one_click_cleanup:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/delete','POST','aigc_one_click_cleanup:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.config/detail','GET','aigc_one_click_cleanup:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.option/lists','GET','aigc_one_click_cleanup:option:lists:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.generate/estimate','POST','aigc_one_click_cleanup:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.generate/index','POST','aigc_one_click_cleanup:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/lists','GET','aigc_one_click_cleanup:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/detail','GET','aigc_one_click_cleanup:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.task/delete','POST','aigc_one_click_cleanup:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.result/lists','GET','aigc_one_click_cleanup:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_one_click_cleanup','app.aigc_one_click_cleanup.result/delete','POST','aigc_one_click_cleanup:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_one_click_cleanup' AND `source`='app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','一键消除','el-icon-Picture',85,'','aigc-one-click-cleanup','','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',40,'app.aigc_one_click_cleanup.config/detail','config','apps/aigc_one_click_cleanup/config','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','消除选项','',35,'app.aigc_one_click_cleanup.option/lists','option','apps/aigc_one_click_cleanup/option','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_option',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','价格配置','',30,'app.aigc_one_click_cleanup.price/detail','price','apps/aigc_one_click_cleanup/price','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','任务记录','',10,'app.aigc_one_click_cleanup.task/lists','task','apps/aigc_one_click_cleanup/task','','',0,1,0,'aigc_one_click_cleanup','app','aigc_one_click_cleanup_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_one_click_cleanup' AND root.`source_menu_key`='aigc_one_click_cleanup';
