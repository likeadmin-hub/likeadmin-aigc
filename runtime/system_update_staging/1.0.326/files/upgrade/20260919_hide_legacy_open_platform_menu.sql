-- 线上旧菜单树兼容：仅在新的一级开放平台已存在时隐藏重复的旧二级节点。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();
SET @open_platform_root_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `source_menu_key` = 'core_channel_manage' AND `pid` = 0
  ORDER BY `id` LIMIT 1
);

UPDATE `la_system_menu`
SET `is_show` = 0, `is_disable` = 1, `update_time` = @now
WHERE @open_platform_root_id IS NOT NULL
  AND `source_menu_key` = 'core_open_platform'
  AND `id` <> @open_platform_root_id;
