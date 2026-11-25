-- BEGIN aigc_video_enhance bootstrap
CREATE TABLE IF NOT EXISTS `la_aigc_video_enhance_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `video_uri` varchar(1000) NOT NULL DEFAULT '',
  `source_url` text NOT NULL,
  `storage_scope` varchar(20) NOT NULL DEFAULT '',
  `storage_engine` varchar(32) NOT NULL DEFAULT '',
  `storage_domain` varchar(512) NOT NULL DEFAULT '',
  `duration` decimal(10,4) NOT NULL,
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `bit_rate` tinyint unsigned NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner_history` (`tenant_id`,`user_id`,`delete_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='视频变清晰任务';
INSERT IGNORE INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`) VALUES ('aigc_video_enhance','视频变清晰','resource/image/common/menu_generator.png','通过视频超分提升画面分辨率，适合短视频画质修复。','aigc','resource/image/apps/aigc_video_enhance/cover.jpg','pc,uniapp',1,846,'1.0.1','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`) VALUES ('aigc_video_enhance','1.0.1','>=1.0.450','local','{"code":"aigc_video_enhance","name":"视频变清晰","version":"1.0.1","require_core":">=1.0.450","description":"通过视频超分提升画面分辨率，适合短视频画质修复。","changelog":"1. 新增视频变清晰工具，支持上传短视频并预览、下载处理结果。","icon":"resource/image/common/menu_generator.png","cover":"resource/image/apps/aigc_video_enhance/cover.jpg","category":"aigc","is_builtin":1,"expire_policy":"allow","sort":846,"frontends":["pc","uniapp"],"api_prefix":"/app/aigc_video_enhance","migrations":"migrations","billing":{"provider":"power_market","actions":["enhance"],"currency":"points"},"frontend_entries":[{"terminal":"pc","entry_key":"aigc_video_enhance","name":"视频变清晰","path":"/ai/tools/aigc_video_enhance","icon":"resource/image/common/menu_generator.png","sort":846,"status":1},{"terminal":"uniapp","entry_key":"aigc_video_enhance","name":"视频变清晰","path":"/apps/aigc_video_enhance/pages/creator/index","icon":"resource/image/common/menu_generator.png","sort":846,"status":1,"meta":{"pages":[{"name":"视频变清晰","path":"/apps/aigc_video_enhance/pages/creator/index"},{"name":"清晰化记录","path":"/apps/aigc_video_enhance/pages/tasks/index"}]}}],"public_assets":[{"source":"assets/cover.jpg","target":"public/resource/image/apps/aigc_video_enhance/cover.jpg"}]}','1. 新增视频变清晰工具，支持上传短视频并预览、下载处理结果。',1,UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','pc','aigc_video_enhance','视频变清晰','/ai/tools/aigc_video_enhance','resource/image/common/menu_generator.png',846,1,'{}',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_frontend_entry` (`app_code`,`terminal`,`entry_key`,`name`,`path`,`icon`,`sort`,`status`,`meta`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','uniapp','aigc_video_enhance','视频变清晰','/apps/aigc_video_enhance/pages/creator/index','resource/image/common/menu_generator.png',846,1,'{"pages":[{"name":"视频变清晰","path":"/apps/aigc_video_enhance/pages/creator/index"},{"name":"清晰化记录","path":"/apps/aigc_video_enhance/pages/tasks/index"}]}',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','app.aigc_video_enhance.config/detail','GET','aigc_video_enhance:config:detail','user',0,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','app.aigc_video_enhance.generate/estimate','POST','aigc_video_enhance:generate:estimate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','app.aigc_video_enhance.generate/index','POST','aigc_video_enhance:generate','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','app.aigc_video_enhance.task/lists','GET','aigc_video_enhance:task:lists','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','app.aigc_video_enhance.task/detail','GET','aigc_video_enhance:task:detail','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('aigc_video_enhance','app.aigc_video_enhance.task/delete','POST','aigc_video_enhance:task:delete','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT id, 'aigc_video_enhance','1.0.1','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM (SELECT 0 AS id UNION SELECT id FROM `la_tenant` WHERE id > 0) t;
-- END aigc_video_enhance bootstrap
