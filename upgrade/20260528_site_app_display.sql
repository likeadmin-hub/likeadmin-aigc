CREATE TABLE IF NOT EXISTS `la_tenant_app_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(80) NOT NULL DEFAULT '' COMMENT '展示标题',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '展示描述',
  `cover_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '封面资源',
  `icon_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '图标资源',
  `virtual_use_count` varchar(50) NOT NULL DEFAULT '' COMMENT '虚拟使用数',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `extra` json DEFAULT NULL COMMENT '扩展配置',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_app` (`tenant_id`,`app_code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用展示配置';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,29,'C','网站轮播','',2,'setting.web.web_banner/lists','banner','setting/website/banner','','',0,1,0,'','core','core_tenant_website_banner',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu` item
  WHERE item.`tenant_id`=0
    AND (item.`source_menu_key`='core_tenant_website_banner' OR item.`perms`='setting.web.web_banner/lists')
);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,parent.`id`,'A','保存','',0,'setting.web.web_banner/save','','','','',0,1,0,'','core','core_tenant_website_banner_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='core_tenant_website_banner'
  AND parent.`tenant_id`=0
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0
      AND item.`perms`='setting.web.web_banner/save'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,parent.`id`,'A','删除','',0,'setting.web.web_banner/delete','','','','',0,1,0,'','core','core_tenant_website_banner_delete',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='core_tenant_website_banner'
  AND parent.`tenant_id`=0
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0
      AND item.`perms`='setting.web.web_banner/delete'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,parent.`id`,'A','状态','',0,'setting.web.web_banner/status','','','','',0,1,0,'','core','core_tenant_website_banner_status',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='core_tenant_website_banner'
  AND parent.`tenant_id`=0
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0
      AND item.`perms`='setting.web.web_banner/status'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,root.`id`,'C','基础配置','',50,CONCAT('app.',root.`app_code`,'.config/detail'),'config',CONCAT('apps/',root.`app_code`,'/config'),'','',0,1,0,root.`app_code`,'app',CONCAT(root.`app_code`,'_config'),0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`source_menu_key` IN ('aigc_image','aigc_video','aigc_digital_human','aigc_canvas','aigc_llm','image_human')
  AND root.`tenant_id`=0
  AND root.`pid`=0
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id`=0
      AND item.`source_menu_key`=CONCAT(root.`app_code`,'_config')
      AND item.`app_code`=root.`app_code`
  );

UPDATE `la_tenant_system_menu`
SET `name`='基础配置'
WHERE `source_menu_key` IN ('aigc_image_config','aigc_video_config','aigc_digital_human_config','aigc_canvas_config','aigc_llm_config','image_human_config')
  AND `source`='app';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
SELECT 'aigc_canvas','app.aigc_canvas.config/detail','GET','aigc_canvas:config:detail','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND `api_path`='app.aigc_canvas.config/detail' AND `scene`='tenant_admin');

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
SELECT 'aigc_canvas','app.aigc_canvas.config/setup','POST','aigc_canvas:config:setup','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND `api_path`='app.aigc_canvas.config/setup' AND `scene`='tenant_admin');
