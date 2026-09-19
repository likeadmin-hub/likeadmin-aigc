-- Preserve existing role grants by reusing the former image-task menu record.
UPDATE `la_tenant_system_menu`
SET `name` = '创作任务',
    `sort` = 19,
    `paths` = 'creation-task',
    `component` = 'apps/aigc_short_drama/creation-task',
    `source_menu_key` = 'aigc_short_drama_creation_task',
    `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_short_drama'
  AND `source` = 'app'
  AND `source_menu_key` = 'aigc_short_drama_image_task';

SET @creation_task_menu_id := (
  SELECT `id` FROM `la_tenant_system_menu`
  WHERE `app_code` = 'aigc_short_drama'
    AND `source` = 'app'
    AND `source_menu_key` = 'aigc_short_drama_creation_task'
  LIMIT 1
);

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`, `menu_id`)
SELECT role_menu.`role_id`, @creation_task_menu_id
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE @creation_task_menu_id IS NOT NULL
  AND menu.`app_code` = 'aigc_short_drama'
  AND menu.`source` = 'app'
  AND menu.`source_menu_key` IN ('aigc_short_drama_shot_video', 'aigc_short_drama_audio_task', 'aigc_short_drama_final_video');

DELETE role_menu FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE menu.`app_code` = 'aigc_short_drama'
  AND menu.`source` = 'app'
  AND menu.`source_menu_key` IN ('aigc_short_drama_shot_video', 'aigc_short_drama_audio_task', 'aigc_short_drama_final_video');

DELETE FROM `la_tenant_system_menu`
WHERE `app_code` = 'aigc_short_drama'
  AND `source` = 'app'
  AND `source_menu_key` IN ('aigc_short_drama_shot_video', 'aigc_short_drama_audio_task', 'aigc_short_drama_final_video');
