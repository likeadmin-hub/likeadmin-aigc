-- 微信开放平台菜单收敛：平台端保留 4 个功能入口，历史菜单保留记录但不展示。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();
SET @open_platform_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform' ORDER BY `id` DESC LIMIT 1);

UPDATE `la_system_menu`
SET `pid`=@open_platform_id, `type`='C',
    `name`=CASE `source_menu_key`
      WHEN 'core_open_platform_config' THEN '平台配置'
      WHEN 'core_open_platform_authorizers' THEN '账号管理'
      WHEN 'core_open_platform_miniprogram' THEN '版本管理'
      WHEN 'core_open_platform_logs' THEN '调用日志'
    END,
    `perms`=CASE `source_menu_key`
      WHEN 'core_open_platform_config' THEN 'open_platform/config'
      WHEN 'core_open_platform_authorizers' THEN 'open_platform/authorizers'
      WHEN 'core_open_platform_miniprogram' THEN 'open_platform/miniprogram'
      WHEN 'core_open_platform_logs' THEN 'open_platform/logs'
    END,
    `paths`=CASE `source_menu_key`
      WHEN 'core_open_platform_config' THEN 'open_platform/config'
      WHEN 'core_open_platform_authorizers' THEN 'open_platform/authorizers'
      WHEN 'core_open_platform_miniprogram' THEN 'open_platform/miniprogram'
      WHEN 'core_open_platform_logs' THEN 'open_platform/logs'
    END,
    `component`=CASE `source_menu_key`
      WHEN 'core_open_platform_config' THEN 'channel/open_platform/config'
      WHEN 'core_open_platform_authorizers' THEN 'channel/open_platform/authorizers'
      WHEN 'core_open_platform_miniprogram' THEN 'channel/open_platform/miniprogram'
      WHEN 'core_open_platform_logs' THEN 'channel/open_platform/logs'
    END,
    `sort`=CASE `source_menu_key`
      WHEN 'core_open_platform_config' THEN 10
      WHEN 'core_open_platform_authorizers' THEN 20
      WHEN 'core_open_platform_miniprogram' THEN 30
      WHEN 'core_open_platform_logs' THEN 40
    END,
    `is_show`=1, `is_disable`=0, `update_time`=@now
WHERE @open_platform_id IS NOT NULL
  AND `source_menu_key` IN ('core_open_platform_config','core_open_platform_authorizers','core_open_platform_miniprogram','core_open_platform_logs');

UPDATE `la_system_menu`
SET `is_show`=0, `is_disable`=1, `update_time`=@now
WHERE `source_menu_key` IN ('core_open_platform_callback','core_open_platform_official','core_open_platform_versions','core_open_platform_authorizations');
