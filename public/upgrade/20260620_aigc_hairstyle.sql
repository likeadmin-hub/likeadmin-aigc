CREATE TABLE IF NOT EXISTS `la_aigc_hairstyle_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_operation` varchar(30) NOT NULL DEFAULT 'hair_style_color',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI换发型配置';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_hairstyle';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_hairstyle','AI换发型','resource/image/common/menu_generator.png','面向人物发型和发色调整的 AI 图片创作应用，复用 AIGC 生图通道完成生成。','aigc','','tenant,pc',0,0,1,860,'1.0.1','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`icon`=VALUES(`icon`),`description`=VALUES(`description`),`category`=VALUES(`category`),`client_tags`=VALUES(`client_tags`),`is_builtin`=VALUES(`is_builtin`),`sort`=VALUES(`sort`),`current_version`=VALUES(`current_version`),`status`=VALUES(`status`),`expire_policy`=VALUES(`expire_policy`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_hairstyle','1.0.1','>=1.0.0','local','{"code":"aigc_hairstyle","name":"AI换发型","version":"1.0.1","require_core":">=1.0.0","description":"面向人物发型和发色调整的 AI 图片创作应用，复用 AIGC 生图通道完成生成。","changelog":"1. 新增 AI 换发型应用。\n2. 支持租户配置提示词模板和示例图片。\n3. PC 端支持本地上传人物图、发型参考图并按操作类型生成。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":860,"frontends":["tenant","pc"],"api_prefix":"/app/aigc_hairstyle","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_hairstyle_admin","name":"AI换发型","path":"/app/aigc_hairstyle","icon":"el-icon-MagicStick","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_hairstyle","name":"AI换发型","path":"/ai/tools/aigc_hairstyle","icon":"resource/image/common/menu_generator.png","sort":92,"status":1}],"dependencies":[{"app_code":"aigc_image","name":"AIGC生图","required_for":"图片生成"}]}','1. 新增 AI 换发型应用。
2. 支持租户配置提示词模板和示例图片。
3. PC 端支持本地上传人物图、发型参考图并按操作类型生成。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`)
VALUES
('aigc_hairstyle','tenant','aigc_hairstyle_admin','AI换发型','/app/aigc_hairstyle','el-icon-MagicStick',100,1,'{}',UNIX_TIMESTAMP()),
('aigc_hairstyle','pc','aigc_hairstyle','AI换发型','/ai/tools/aigc_hairstyle','resource/image/common/menu_generator.png',92,1,'{}',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`path`=VALUES(`path`),`icon`=VALUES(`icon`),`sort`=VALUES(`sort`),`status`=VALUES(`status`),`meta`=VALUES(`meta`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_hairstyle','app.aigc_hairstyle.config/detail','GET','aigc_hairstyle:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.config/setup','POST','aigc_hairstyle:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.config/detail','GET','aigc_hairstyle:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.generate/estimate','POST','aigc_hairstyle:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.generate/index','POST','aigc_hairstyle:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.task/lists','GET','aigc_hairstyle:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.task/detail','GET','aigc_hairstyle:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.result/lists','GET','aigc_hairstyle:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=VALUES(`need_login`),`need_role_permission`=VALUES(`need_role_permission`),`status`=VALUES(`status`),`update_time`=VALUES(`update_time`);

DELETE FROM `la_tenant_system_menu`
WHERE `app_code`='aigc_hairstyle'
  AND `source`='app'
  AND `source_menu_key` IN ('aigc_hairstyle','aigc_hairstyle_config');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','AI换发型','el-icon-MagicStick',92,'','aigc-hairstyle','','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT 0 AS `id` UNION SELECT `id` FROM `la_tenant`) t;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`,root.`id`,'C','基础配置','',0,'app.aigc_hairstyle.config/detail','config','apps/aigc_hairstyle/config','','',0,1,0,'aigc_hairstyle','app','aigc_hairstyle_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code`='aigc_hairstyle' AND root.`source_menu_key`='aigc_hairstyle';
