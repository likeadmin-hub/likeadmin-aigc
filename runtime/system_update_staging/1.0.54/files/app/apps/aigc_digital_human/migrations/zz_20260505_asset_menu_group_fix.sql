INSERT INTO `la_tenant_system_menu` (
  `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`,
  `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`
)
SELECT
  root.`tenant_id`, root.`id`, 'M', '形象管理', '', 80, '', 'avatar-manage', '', '', '',
  0, 1, 0, 'aigc_digital_human', 'app', 'aigc_digital_human_avatar_manage', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code` = 'aigc_digital_human'
  AND root.`source_menu_key` = 'aigc_digital_human'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id` = root.`tenant_id`
      AND item.`app_code` = 'aigc_digital_human'
      AND item.`source_menu_key` = 'aigc_digital_human_avatar_manage'
  );

INSERT INTO `la_tenant_system_menu` (
  `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`,
  `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`
)
SELECT
  root.`tenant_id`, root.`id`, 'M', '音色管理', '', 70, '', 'voice-manage', '', '', '',
  0, 1, 0, 'aigc_digital_human', 'app', 'aigc_digital_human_voice_manage', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` root
WHERE root.`app_code` = 'aigc_digital_human'
  AND root.`source_menu_key` = 'aigc_digital_human'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id` = root.`tenant_id`
      AND item.`app_code` = 'aigc_digital_human'
      AND item.`source_menu_key` = 'aigc_digital_human_voice_manage'
  );

UPDATE `la_tenant_system_menu` public_avatar
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = public_avatar.`tenant_id`
 AND parent.`app_code` = 'aigc_digital_human'
 AND parent.`source_menu_key` = 'aigc_digital_human_avatar_manage'
SET public_avatar.`pid` = parent.`id`,
    public_avatar.`sort` = 20,
    public_avatar.`update_time` = UNIX_TIMESTAMP()
WHERE public_avatar.`app_code` = 'aigc_digital_human'
  AND public_avatar.`source_menu_key` = 'aigc_digital_human_public_avatar';

UPDATE `la_tenant_system_menu` public_voice
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = public_voice.`tenant_id`
 AND parent.`app_code` = 'aigc_digital_human'
 AND parent.`source_menu_key` = 'aigc_digital_human_voice_manage'
SET public_voice.`pid` = parent.`id`,
    public_voice.`sort` = 20,
    public_voice.`update_time` = UNIX_TIMESTAMP()
WHERE public_voice.`app_code` = 'aigc_digital_human'
  AND public_voice.`source_menu_key` = 'aigc_digital_human_public_voice';

INSERT INTO `la_tenant_system_menu` (
  `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`,
  `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`
)
SELECT
  parent.`tenant_id`, parent.`id`, 'C', '用户形象', '', 10, 'app.aigc_digital_human.user_avatar/lists',
  'user-avatar', 'apps/aigc_digital_human/user-avatar', '', '',
  0, 1, 0, 'aigc_digital_human', 'app', 'aigc_digital_human_user_avatar', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`app_code` = 'aigc_digital_human'
  AND parent.`source_menu_key` = 'aigc_digital_human_avatar_manage'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id` = parent.`tenant_id`
      AND item.`app_code` = 'aigc_digital_human'
      AND item.`source_menu_key` = 'aigc_digital_human_user_avatar'
  );

INSERT INTO `la_tenant_system_menu` (
  `tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`,
  `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`
)
SELECT
  parent.`tenant_id`, parent.`id`, 'C', '用户音色', '', 10, 'app.aigc_digital_human.user_voice/lists',
  'user-voice', 'apps/aigc_digital_human/user-voice', '', '',
  0, 1, 0, 'aigc_digital_human', 'app', 'aigc_digital_human_user_voice', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`app_code` = 'aigc_digital_human'
  AND parent.`source_menu_key` = 'aigc_digital_human_voice_manage'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` item
    WHERE item.`tenant_id` = parent.`tenant_id`
      AND item.`app_code` = 'aigc_digital_human'
      AND item.`source_menu_key` = 'aigc_digital_human_user_voice'
  );
