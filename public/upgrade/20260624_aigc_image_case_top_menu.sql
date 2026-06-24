UPDATE `la_tenant_system_menu`
SET `pid` = 0,
    `type` = 'C',
    `name` = '案例广场',
    `icon` = 'el-icon-PictureFilled',
    `sort` = 98,
    `perms` = 'app.aigc_image.case/lists',
    `paths` = 'aigc-image-case',
    `component` = 'apps/aigc_image/case',
    `selected` = 'aigc-image-case',
    `is_show` = 1,
    `is_disable` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_image'
  AND `source` = 'app'
  AND `source_menu_key` = 'aigc_image_case';

INSERT INTO `la_tenant_system_menu`
(`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT ta.`tenant_id`,0,'C','案例广场','el-icon-PictureFilled',98,'app.aigc_image.case/lists','aigc-image-case','apps/aigc_image/case','aigc-image-case','',0,1,0,'aigc_image','app','aigc_image_case',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_app` ta
WHERE ta.`app_code` = 'aigc_image'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` m
    WHERE m.`tenant_id` = ta.`tenant_id`
      AND m.`app_code` = 'aigc_image'
      AND m.`source_menu_key` = 'aigc_image_case'
  );
