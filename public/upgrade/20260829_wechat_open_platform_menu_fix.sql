-- 微信开放平台菜单修复：平台端按功能加载独立页面，租户端只保留授权。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();

UPDATE `la_system_menu`
SET `component` = CASE `source_menu_key`
  WHEN 'core_open_platform_config' THEN 'channel/open_platform/config'
  WHEN 'core_open_platform_callback' THEN 'channel/open_platform/callback'
  WHEN 'core_open_platform_authorizers' THEN 'channel/open_platform/authorizers'
  WHEN 'core_open_platform_official' THEN 'channel/open_platform/official'
  WHEN 'core_open_platform_miniprogram' THEN 'channel/open_platform/miniprogram'
  WHEN 'core_open_platform_versions' THEN 'channel/open_platform/versions'
  WHEN 'core_open_platform_authorizations' THEN 'channel/open_platform/authorizations'
  WHEN 'core_open_platform_logs' THEN 'channel/open_platform/logs'
  ELSE `component`
END,
`update_time` = @now
WHERE `source_menu_key` IN (
  'core_open_platform_config','core_open_platform_callback','core_open_platform_authorizers',
  'core_open_platform_official','core_open_platform_miniprogram','core_open_platform_versions',
  'core_open_platform_authorizations','core_open_platform_logs'
);

UPDATE `la_tenant_system_menu`
SET `name` = '微信配置', `update_time` = @now
WHERE `perms` = 'channel.open_setting/getConfig' AND `name` = '微信开放平台';

UPDATE `la_tenant_system_menu`
SET `is_show` = 0, `is_disable` = 1, `update_time` = @now
WHERE `source_menu_key` IN (
  'core_tenant_open_platform_official','core_tenant_open_platform_mini',
  'core_tenant_open_platform_authorizations'
) OR (`source_menu_key` LIKE 'core_tenant_open_%'
    AND `source_menu_key` NOT IN ('core_tenant_open_platform','core_tenant_open_platform_bind','core_tenant_open_platform_accounts'));

UPDATE `la_tenant_system_menu`
SET `is_show` = 1, `is_disable` = 0, `type` = 'C',
    `component` = 'channel/open_platform/index', `update_time` = @now
WHERE `source_menu_key` IN ('core_tenant_open_platform','core_tenant_open_platform_bind','core_tenant_open_platform_accounts');
UPDATE `la_tenant_system_menu`
SET `type` = 'M', `perms` = '', `component` = '', `update_time` = @now
WHERE `source_menu_key` = 'core_tenant_open_platform';
