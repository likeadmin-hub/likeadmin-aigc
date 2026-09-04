-- 租户端渠道设置：收敛为卡片总览，保留原有手动配置与授权路由。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`, root.`id`, 'C', '渠道总览', 'el-icon-Grid', 110,
       'channel.overview/index', 'overview', 'channel/index', '', '', 0, 1, 0,
       '', 'core', 'core_tenant_channel_overview', 1, @now, @now
FROM `la_tenant_system_menu` root
WHERE root.`pid`=0
  AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
  AND NOT EXISTS (
      SELECT 1 FROM `la_tenant_system_menu` item
      WHERE item.`tenant_id`=root.`tenant_id` AND item.`source_menu_key`='core_tenant_channel_overview'
  );

UPDATE `la_tenant_system_menu` overview
INNER JOIN `la_tenant_system_menu` root
    ON root.`tenant_id`=overview.`tenant_id`
   AND root.`pid`=0
   AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
SET overview.`pid`=root.`id`, overview.`type`='C', overview.`name`='渠道总览',
    overview.`icon`='el-icon-Grid', overview.`sort`=110, overview.`perms`='channel.overview/index',
    overview.`paths`='overview', overview.`component`='channel/index', overview.`is_show`=1,
    overview.`is_disable`=0, overview.`update_time`=@now
WHERE overview.`source_menu_key`='core_tenant_channel_overview';

UPDATE `la_tenant_system_menu` child
INNER JOIN `la_tenant_system_menu` root
    ON root.`tenant_id`=child.`tenant_id`
   AND root.`pid`=0
   AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
SET child.`is_show`=0, child.`is_disable`=0, child.`update_time`=@now
WHERE child.`pid`=root.`id`
  AND child.`source_menu_key`<>'core_tenant_channel_overview';

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`, overview.`id`
FROM `la_tenant_system_role_menu` role_menu
INNER JOIN `la_tenant_system_menu` source_menu ON source_menu.`id`=role_menu.`menu_id`
LEFT JOIN `la_tenant_system_menu` parent_one ON parent_one.`id`=source_menu.`pid`
LEFT JOIN `la_tenant_system_menu` parent_two ON parent_two.`id`=parent_one.`pid`
LEFT JOIN `la_tenant_system_menu` parent_three ON parent_three.`id`=parent_two.`pid`
INNER JOIN `la_tenant_system_menu` root
    ON root.`tenant_id`=source_menu.`tenant_id`
   AND root.`pid`=0
   AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
INNER JOIN `la_tenant_system_menu` overview
    ON overview.`tenant_id`=root.`tenant_id`
   AND overview.`source_menu_key`='core_tenant_channel_overview'
WHERE root.`id` IN (source_menu.`id`, parent_one.`id`, parent_two.`id`, parent_three.`id`);

-- 卡片入口页均为隐藏路由：页面跳转后侧栏持续选中渠道总览。
UPDATE `la_tenant_system_menu` item
INNER JOIN `la_tenant_system_menu` root
    ON root.`tenant_id`=item.`tenant_id`
   AND root.`pid`=0
   AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
LEFT JOIN `la_tenant_system_menu` parent_one ON parent_one.`id`=item.`pid`
LEFT JOIN `la_tenant_system_menu` parent_two ON parent_two.`id`=parent_one.`pid`
SET item.`selected`='channel/overview', item.`update_time`=@now
WHERE item.`type` IN ('M','C')
  AND (item.`pid`=root.`id` OR parent_one.`pid`=root.`id` OR parent_two.`pid`=root.`id`);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT root.`tenant_id`, root.`id`, 'C', item.`name`, '', item.`sort`, item.`perms`, item.`paths`, item.`component`, 'channel/overview', '', 0, 0, 0, '', 'core', item.`source_menu_key`, 1, @now, @now
FROM `la_tenant_system_menu` root
INNER JOIN (
    SELECT '授权入口' AS `name`, 109 AS `sort`, 'channel.open_platform/authUrl' AS `perms`, 'authorize' AS `paths`, 'channel/open_platform/index' AS `component`, 'core_tenant_channel_authorize' AS `source_menu_key`
    UNION ALL SELECT '公号管理', 108, 'channel.official_account_setting/getConfig', 'official', 'channel/open_platform/official', 'core_tenant_channel_official'
    UNION ALL SELECT '小程管理', 107, 'channel.open_platform/versions', 'miniprogram', 'channel/open_platform/miniprogram', 'core_tenant_channel_miniprogram'
) item
WHERE root.`pid`=0
  AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
  AND NOT EXISTS (
      SELECT 1 FROM `la_tenant_system_menu` menu
      WHERE menu.`tenant_id`=root.`tenant_id` AND menu.`source_menu_key`=item.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` menu
INNER JOIN `la_tenant_system_menu` root
    ON root.`tenant_id`=menu.`tenant_id`
   AND root.`pid`=0
   AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
SET menu.`pid`=root.`id`, menu.`type`='C', menu.`icon`='', menu.`selected`='channel/overview',
    menu.`is_show`=0, menu.`is_disable`=0, menu.`update_time`=@now,
    menu.`name`=CASE menu.`source_menu_key`
        WHEN 'core_tenant_channel_authorize' THEN '授权入口'
        WHEN 'core_tenant_channel_official' THEN '公号管理'
        WHEN 'core_tenant_channel_miniprogram' THEN '小程管理'
    END,
    menu.`sort`=CASE menu.`source_menu_key`
        WHEN 'core_tenant_channel_authorize' THEN 109
        WHEN 'core_tenant_channel_official' THEN 108
        WHEN 'core_tenant_channel_miniprogram' THEN 107
    END,
    menu.`perms`=CASE menu.`source_menu_key`
        WHEN 'core_tenant_channel_authorize' THEN 'channel.open_platform/authUrl'
        WHEN 'core_tenant_channel_official' THEN 'channel.official_account_setting/getConfig'
        WHEN 'core_tenant_channel_miniprogram' THEN 'channel.open_platform/versions'
    END,
    menu.`paths`=CASE menu.`source_menu_key`
        WHEN 'core_tenant_channel_authorize' THEN 'authorize'
        WHEN 'core_tenant_channel_official' THEN 'official'
        WHEN 'core_tenant_channel_miniprogram' THEN 'miniprogram'
    END,
    menu.`component`=CASE menu.`source_menu_key`
        WHEN 'core_tenant_channel_authorize' THEN 'channel/open_platform/index'
        WHEN 'core_tenant_channel_official' THEN 'channel/open_platform/official'
        WHEN 'core_tenant_channel_miniprogram' THEN 'channel/open_platform/miniprogram'
    END
WHERE menu.`source_menu_key` IN ('core_tenant_channel_authorize','core_tenant_channel_official','core_tenant_channel_miniprogram');

UPDATE `la_tenant_system_menu`
SET `is_show`=0, `is_disable`=0, `update_time`=@now
WHERE `source_menu_key` LIKE 'core_tenant_open_platform%';

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`, card.`id`
FROM `la_tenant_system_role_menu` role_menu
INNER JOIN `la_tenant_system_menu` overview ON overview.`id`=role_menu.`menu_id`
INNER JOIN `la_tenant_system_menu` card
    ON card.`tenant_id`=overview.`tenant_id`
   AND card.`source_menu_key` IN ('core_tenant_channel_authorize','core_tenant_channel_official','core_tenant_channel_miniprogram')
WHERE overview.`source_menu_key`='core_tenant_channel_overview';
