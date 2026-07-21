-- Repair tenant menu/API completeness for existing installations.

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_llm','app.aigc_llm.model/batchStatus','POST','aigc_llm:model:status:platform','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_llm','app.aigc_llm.model/syncTextModels','POST','aigc_llm:model:save:platform','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_canvas','app.aigc_canvas.agent_chat/runStatus','GET','aigc_canvas:agent_chat:run_status','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_digital_human','app.aigc_digital_human.generate/assistScript','POST','aigc_digital_human:generate:assist_script','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_digital_human','app.aigc_digital_human.voice/preview','POST','aigc_digital_human:voice:preview:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.task/delete','POST','aigc_fitting:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_fitting','app.aigc_fitting.result/delete','POST','aigc_fitting:result:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_hairstyle','app.aigc_hairstyle.task/delete','POST','aigc_hairstyle:task:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_video','app.aigc_video.task/refresh','POST','aigc_video:task:refresh:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`permission_key`=VALUES(`permission_key`),
`need_login`=VALUES(`need_login`),
`need_role_permission`=VALUES(`need_role_permission`),
`status`=VALUES(`status`),
`update_time`=VALUES(`update_time`);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,0,'M','服饰套图','el-icon-Picture',84,'','aigc-fashion-lookbook','','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='aigc_fashion_lookbook');
SET @template_fashion_parent_id := (SELECT `id` FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='aigc_fashion_lookbook' LIMIT 1);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@template_fashion_parent_id,'C','基础配置','',40,'app.aigc_fashion_lookbook.config/detail','config','apps/aigc_fashion_lookbook/config','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @template_fashion_parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='aigc_fashion_lookbook_config');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@template_fashion_parent_id,'C','模特预设','',35,'app.aigc_fashion_lookbook.model/lists','model','apps/aigc_fashion_lookbook/model','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_model',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @template_fashion_parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='aigc_fashion_lookbook_model');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@template_fashion_parent_id,'C','价格配置','',30,'app.aigc_fashion_lookbook.price/detail','price','apps/aigc_fashion_lookbook/price','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @template_fashion_parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='aigc_fashion_lookbook_price');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@template_fashion_parent_id,'C','任务记录','',10,'app.aigc_fashion_lookbook.task/lists','task','apps/aigc_fashion_lookbook/task','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @template_fashion_parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='aigc_fashion_lookbook_task');

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent ON parent.`tenant_id`=0 AND parent.`source_menu_key`='aigc_fashion_lookbook'
SET child.`pid`=parent.`id`, child.`app_code`='aigc_fashion_lookbook', child.`source`='app', child.`is_core`=0, child.`update_time`=UNIX_TIMESTAMP()
WHERE child.`tenant_id`=0 AND child.`source_menu_key` IN ('aigc_fashion_lookbook_config','aigc_fashion_lookbook_model','aigc_fashion_lookbook_price','aigc_fashion_lookbook_task');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','服饰套图','el-icon-Picture',84,'','aigc-fashion-lookbook','','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t
JOIN `la_tenant_app` ta ON ta.`tenant_id`=t.`id` AND ta.`app_code`='aigc_fashion_lookbook' AND ta.`buy_status`='paid' AND ta.`shelf_status`='on' AND ta.`enable_status`='enabled'
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu` m
  WHERE m.`tenant_id`=t.`id` AND m.`source_menu_key`='aigc_fashion_lookbook'
);

UPDATE `la_tenant_system_menu` parent
SET parent.`pid`=0,parent.`type`='M',parent.`name`='服饰套图',parent.`icon`='el-icon-Picture',parent.`sort`=84,parent.`perms`='',parent.`paths`='aigc-fashion-lookbook',parent.`component`='',parent.`is_show`=1,parent.`is_disable`=0,parent.`app_code`='aigc_fashion_lookbook',parent.`source`='app',parent.`is_core`=0,parent.`update_time`=UNIX_TIMESTAMP()
WHERE parent.`tenant_id`<>0 AND parent.`source_menu_key`='aigc_fashion_lookbook';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','基础配置','',40,'app.aigc_fashion_lookbook.config/detail','config','apps/aigc_fashion_lookbook/config','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`tenant_id`<>0 AND parent.`source_menu_key`='aigc_fashion_lookbook'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='aigc_fashion_lookbook_config'
  );
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','模特预设','',35,'app.aigc_fashion_lookbook.model/lists','model','apps/aigc_fashion_lookbook/model','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_model',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`tenant_id`<>0 AND parent.`source_menu_key`='aigc_fashion_lookbook'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='aigc_fashion_lookbook_model'
  );
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','价格配置','',30,'app.aigc_fashion_lookbook.price/detail','price','apps/aigc_fashion_lookbook/price','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_price',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`tenant_id`<>0 AND parent.`source_menu_key`='aigc_fashion_lookbook'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='aigc_fashion_lookbook_price'
  );
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','任务记录','',10,'app.aigc_fashion_lookbook.task/lists','task','apps/aigc_fashion_lookbook/task','','',0,1,0,'aigc_fashion_lookbook','app','aigc_fashion_lookbook_task',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`tenant_id`<>0 AND parent.`source_menu_key`='aigc_fashion_lookbook'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='aigc_fashion_lookbook_task'
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent ON parent.`tenant_id`=child.`tenant_id` AND parent.`source_menu_key`='aigc_fashion_lookbook'
SET child.`pid`=parent.`id`,
    child.`type`='C',
    child.`name`=CASE child.`source_menu_key`
      WHEN 'aigc_fashion_lookbook_config' THEN '基础配置'
      WHEN 'aigc_fashion_lookbook_model' THEN '模特预设'
      WHEN 'aigc_fashion_lookbook_price' THEN '价格配置'
      ELSE '任务记录'
    END,
    child.`sort`=CASE child.`source_menu_key`
      WHEN 'aigc_fashion_lookbook_config' THEN 40
      WHEN 'aigc_fashion_lookbook_model' THEN 35
      WHEN 'aigc_fashion_lookbook_price' THEN 30
      ELSE 10
    END,
    child.`perms`=CASE child.`source_menu_key`
      WHEN 'aigc_fashion_lookbook_config' THEN 'app.aigc_fashion_lookbook.config/detail'
      WHEN 'aigc_fashion_lookbook_model' THEN 'app.aigc_fashion_lookbook.model/lists'
      WHEN 'aigc_fashion_lookbook_price' THEN 'app.aigc_fashion_lookbook.price/detail'
      ELSE 'app.aigc_fashion_lookbook.task/lists'
    END,
    child.`paths`=CASE child.`source_menu_key`
      WHEN 'aigc_fashion_lookbook_config' THEN 'config'
      WHEN 'aigc_fashion_lookbook_model' THEN 'model'
      WHEN 'aigc_fashion_lookbook_price' THEN 'price'
      ELSE 'task'
    END,
    child.`component`=CASE child.`source_menu_key`
      WHEN 'aigc_fashion_lookbook_config' THEN 'apps/aigc_fashion_lookbook/config'
      WHEN 'aigc_fashion_lookbook_model' THEN 'apps/aigc_fashion_lookbook/model'
      WHEN 'aigc_fashion_lookbook_price' THEN 'apps/aigc_fashion_lookbook/price'
      ELSE 'apps/aigc_fashion_lookbook/task'
    END,
    child.`is_show`=1,
    child.`is_disable`=0,
    child.`app_code`='aigc_fashion_lookbook',
    child.`source`='app',
    child.`is_core`=0,
    child.`update_time`=UNIX_TIMESTAMP()
WHERE child.`tenant_id`<>0
  AND child.`source_menu_key` IN ('aigc_fashion_lookbook_config','aigc_fashion_lookbook_model','aigc_fashion_lookbook_price','aigc_fashion_lookbook_task');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT website.`tenant_id`,website.`id`,'C','网站轮播','',2,'setting.web.web_banner/lists','banner','setting/website/banner','','',0,1,0,'','core','core_tenant_website_banner',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` website
WHERE website.`tenant_id`<>0 AND website.`paths`='website' AND website.`type`='M'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=website.`tenant_id` AND exists_menu.`source_menu_key`='core_tenant_website_banner'
  );

UPDATE `la_tenant_system_menu` banner
JOIN `la_tenant_system_menu` website ON website.`tenant_id`=banner.`tenant_id` AND website.`paths`='website' AND website.`type`='M'
SET banner.`pid`=website.`id`,banner.`name`='网站轮播',banner.`perms`='setting.web.web_banner/lists',banner.`paths`='banner',banner.`component`='setting/website/banner',banner.`is_show`=1,banner.`is_disable`=0,banner.`source`='core',banner.`is_core`=1,banner.`update_time`=UNIX_TIMESTAMP()
WHERE banner.`tenant_id`<>0 AND banner.`source_menu_key`='core_tenant_website_banner';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT banner.`tenant_id`,banner.`id`,'A',item.`name`,'',0,item.`perms`,'','','','',0,1,0,'','core',item.`source_menu_key`,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` banner
JOIN (
  SELECT '保存' AS `name`, 'setting.web.web_banner/save' AS `perms`, 'core_tenant_website_banner_save' AS `source_menu_key`
  UNION ALL SELECT '删除','setting.web.web_banner/delete','core_tenant_website_banner_delete'
  UNION ALL SELECT '状态','setting.web.web_banner/status','core_tenant_website_banner_status'
) item
WHERE banner.`tenant_id`<>0 AND banner.`source_menu_key`='core_tenant_website_banner'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=banner.`tenant_id` AND exists_menu.`source_menu_key`=item.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` banner ON banner.`tenant_id`=child.`tenant_id` AND banner.`source_menu_key`='core_tenant_website_banner'
SET child.`pid`=banner.`id`,child.`source`='core',child.`is_core`=1,child.`is_show`=1,child.`is_disable`=0,child.`update_time`=UNIX_TIMESTAMP()
WHERE child.`tenant_id`<>0
  AND child.`source_menu_key` IN ('core_tenant_website_banner_save','core_tenant_website_banner_delete','core_tenant_website_banner_status');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT template_menu.`tenant_id`,template_menu.`id`,'A',item.`name`,'',0,item.`perms`,'','','','',0,0,0,'','core',item.`source_menu_key`,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` template_menu
JOIN (
  SELECT '导出模板' AS `name`, 'decorate.template/export' AS `perms`, 'core_tenant_decorate_template_export' AS `source_menu_key`
  UNION ALL SELECT '导入模板','decorate.template/import','core_tenant_decorate_template_import'
  UNION ALL SELECT '数据源','decorate.data/sources','core_tenant_decorate_data_sources'
) item
WHERE template_menu.`tenant_id`<>0
  AND template_menu.`paths`='template'
  AND template_menu.`component`='decoration/template/index'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id`=template_menu.`tenant_id` AND exists_menu.`source_menu_key`=item.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` template_menu ON template_menu.`tenant_id`=child.`tenant_id` AND template_menu.`paths`='template' AND template_menu.`component`='decoration/template/index'
SET child.`pid`=template_menu.`id`,child.`source`='core',child.`is_core`=1,child.`is_show`=0,child.`is_disable`=0,child.`update_time`=UNIX_TIMESTAMP()
WHERE child.`tenant_id`<>0
  AND child.`source_menu_key` IN ('core_tenant_decorate_template_export','core_tenant_decorate_template_import','core_tenant_decorate_data_sources');

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role.`id`, menu.`id`
FROM `la_tenant_system_role` role
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id`=role.`tenant_id`
WHERE menu.`source_menu_key` IN (
    'aigc_fashion_lookbook','aigc_fashion_lookbook_config','aigc_fashion_lookbook_model','aigc_fashion_lookbook_price','aigc_fashion_lookbook_task',
    'core_tenant_website_banner','core_tenant_website_banner_save','core_tenant_website_banner_delete','core_tenant_website_banner_status',
    'core_tenant_decorate_template_export','core_tenant_decorate_template_import','core_tenant_decorate_data_sources'
  )
  AND (role.`delete_time` IS NULL OR role.`delete_time`=0);
