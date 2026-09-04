-- 微信开放平台菜单收敛：渠道管理改为开放平台，功能入口提升为二级并补齐图标。
-- 保留旧 source_menu_key 和菜单记录，避免角色权限及历史链接失效。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();
SET @legacy_channel_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `source_menu_key`='core_channel_manage' AND `pid`=0
  ORDER BY `id` LIMIT 1
);
SET @legacy_open_platform_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `source_menu_key`='core_open_platform'
  ORDER BY `id` DESC LIMIT 1
);

-- 已有渠道管理时沿用其 ID，避免改变现有角色授权；没有时直接提升开放平台节点。
UPDATE `la_system_menu`
SET `name`='开放平台', `icon`='local-icon-weixin', `type`='M',
    `paths`='open_platform', `perms`='', `component`='',
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE `id`=@legacy_channel_id;

SET @open_platform_root_id := COALESCE(@legacy_channel_id, @legacy_open_platform_id);
INSERT INTO `la_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,'M','开放平台','local-icon-weixin',500,'','open_platform','','','',0,1,0,'','core','core_open_platform',1,@now,@now
WHERE @open_platform_root_id IS NULL;
SET @open_platform_root_id := COALESCE(
  @open_platform_root_id,
  (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform' AND `pid`=0 ORDER BY `id` DESC LIMIT 1)
);
UPDATE `la_system_menu`
SET `pid`=0, `type`='M', `name`='开放平台', `icon`='local-icon-weixin',
    `paths`='open_platform', `perms`='', `component`='',
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE `id`=@open_platform_root_id;

-- 将原开放平台下的功能入口提升到新的一级菜单。
UPDATE `la_system_menu`
SET `pid`=@open_platform_root_id, `update_time`=@now
WHERE @open_platform_root_id IS NOT NULL
  AND `source_menu_key` IN (
    'core_open_platform_config','core_open_platform_authorizers',
    'core_open_platform_miniprogram','core_open_platform_logs'
  );

UPDATE `la_system_menu`
SET `name`='平台配置', `icon`='el-icon-Setting', `type`='C',
    `perms`='open_platform/config', `paths`='config',
    `component`='channel/open_platform/config', `sort`=10,
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE `source_menu_key`='core_open_platform_config';
UPDATE `la_system_menu`
SET `name`='账号管理', `icon`='el-icon-User', `type`='C',
    `perms`='open_platform/authorizers', `paths`='authorizers',
    `component`='channel/open_platform/authorizers', `sort`=20,
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE `source_menu_key`='core_open_platform_authorizers';
UPDATE `la_system_menu`
SET `name`='版本管理', `icon`='el-icon-Connection', `type`='C',
    `perms`='open_platform/miniprogram', `paths`='miniprogram',
    `component`='channel/open_platform/miniprogram', `sort`=30,
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE `source_menu_key`='core_open_platform_miniprogram';
UPDATE `la_system_menu`
SET `name`='调用日志', `icon`='el-icon-Document', `type`='C',
    `perms`='open_platform/logs', `paths`='logs',
    `component`='channel/open_platform/logs', `sort`=40,
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE `source_menu_key`='core_open_platform_logs';

-- 回调、公号、版本、授权记录保留为隐藏历史路由，不出现在侧边栏。
UPDATE `la_system_menu`
SET `is_show`=0, `is_disable`=1, `update_time`=@now
WHERE `source_menu_key` IN (
  'core_open_platform_callback','core_open_platform_official',
  'core_open_platform_versions','core_open_platform_authorizations'
);

-- 旧中间节点只保留记录，不再参与菜单树，避免出现三级结构。
UPDATE `la_system_menu`
SET `is_show`=0, `is_disable`=1, `update_time`=@now
WHERE `source_menu_key`='core_open_platform'
  AND `id`<>COALESCE(@open_platform_root_id,0);

-- 非超级管理员沿用原开放平台节点权限，并补授新的一级节点。
INSERT IGNORE INTO `la_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`, @open_platform_root_id
FROM `la_system_role_menu` rm
WHERE @open_platform_root_id IS NOT NULL
  AND rm.`menu_id` IN (
    @legacy_open_platform_id, @open_platform_root_id,
    (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform_config'),
    (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform_authorizers'),
    (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform_miniprogram'),
    (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform_logs')
  );
