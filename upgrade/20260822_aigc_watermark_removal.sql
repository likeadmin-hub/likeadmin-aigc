CREATE TABLE IF NOT EXISTS `la_aigc_watermark_removal_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短视频去水印配置';

CREATE TABLE IF NOT EXISTS `la_aigc_watermark_removal_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(160) NOT NULL DEFAULT '',
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `source_url` varchar(1000) NOT NULL DEFAULT '',
  `request_snapshot` text,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_tenant_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_user_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_user` (`tenant_id`,`user_id`), KEY `idx_consumption` (`consumption_id`), KEY `idx_status` (`tenant_id`,`status`), KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短视频去水印任务';

CREATE TABLE IF NOT EXISTS `la_aigc_watermark_removal_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_task_video` (`tenant_id`,`task_id`,`video_uri`), KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短视频去水印结果';

DELETE FROM `la_app` WHERE `code` = 'aigc_watermark_removal';
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_watermark_removal','短视频去水印','resource/image/common/menu_generator.png','输入短视频分享链接，快速生成无水印视频并支持下载。','aigc','','tenant,pc',0,0,0,858,'1.0.0','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

DELETE FROM `la_app_frontend_entry` WHERE `app_code` = 'aigc_watermark_removal';
INSERT INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`update_time`) VALUES
('aigc_watermark_removal','tenant','aigc_watermark_removal_admin','短视频去水印','/app/aigc_watermark_removal','el-icon-VideoCamera',89,1,'{}',UNIX_TIMESTAMP()),
('aigc_watermark_removal','pc','aigc_watermark_removal','短视频去水印','/ai/tools/aigc_watermark_removal','resource/image/common/menu_generator.png',83,1,'{}',UNIX_TIMESTAMP());

INSERT INTO `la_app_plan` (`app_code`,`name`,`duration_months`,`open_points`,`renew_points`,`status`,`sort`,`create_time`,`update_time`)
SELECT 'aigc_watermark_removal','一年套餐',12,0.00,0.00,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_plan` WHERE `app_code`='aigc_watermark_removal');

DELETE FROM `la_app_api` WHERE `app_code` = 'aigc_watermark_removal';
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES
('aigc_watermark_removal','app.aigc_watermark_removal.config/detail','GET','aigc_watermark_removal:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.config/setup','POST','aigc_watermark_removal:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/lists','GET','aigc_watermark_removal:task:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/detail','GET','aigc_watermark_removal:task:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/retry','POST','aigc_watermark_removal:task:retry','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/delete','POST','aigc_watermark_removal:task:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.config/detail','GET','aigc_watermark_removal:config:user','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.generate/estimate','POST','aigc_watermark_removal:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.generate/index','POST','aigc_watermark_removal:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/lists','GET','aigc_watermark_removal:task:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/detail','GET','aigc_watermark_removal:task:detail:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.task/delete','POST','aigc_watermark_removal:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.result/lists','GET','aigc_watermark_removal:result:lists:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_watermark_removal','app.aigc_watermark_removal.result/delete','POST','aigc_watermark_removal:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
