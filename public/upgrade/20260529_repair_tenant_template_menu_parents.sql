UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = 0
 AND parent.`source_menu_key` = 'core_tenant_website_banner'
LEFT JOIN `la_tenant_system_menu` existing_parent
  ON existing_parent.`tenant_id` = 0
 AND existing_parent.`id` = child.`pid`
SET child.`pid` = parent.`id`
WHERE child.`tenant_id` = 0
  AND child.`source_menu_key` IN ('core_tenant_website_banner_save','core_tenant_website_banner_delete','core_tenant_website_banner_status')
  AND existing_parent.`id` IS NULL;

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` root
  ON root.`tenant_id` = 0
 AND root.`pid` = 0
 AND root.`source_menu_key` = REPLACE(child.`source_menu_key`, '_config', '')
LEFT JOIN `la_tenant_system_menu` existing_parent
  ON existing_parent.`tenant_id` = 0
 AND existing_parent.`id` = child.`pid`
SET child.`pid` = root.`id`
WHERE child.`tenant_id` = 0
  AND child.`source` = 'app'
  AND child.`source_menu_key` IN ('aigc_image_config','aigc_video_config','aigc_digital_human_config','aigc_canvas_config','aigc_llm_config','image_human_config')
  AND existing_parent.`id` IS NULL;

DELETE child FROM `la_tenant_system_menu` child
JOIN (
  SELECT `source_menu_key`, MIN(`id`) AS keep_id
  FROM (
    SELECT `id`, `source_menu_key`
    FROM `la_tenant_system_menu`
    WHERE `tenant_id` = 0
      AND `source_menu_key` IN ('core_tenant_website_banner_save','core_tenant_website_banner_delete','core_tenant_website_banner_status','aigc_image_config','aigc_video_config','aigc_digital_human_config','aigc_canvas_config','aigc_llm_config','image_human_config')
  ) template_menu
  GROUP BY `source_menu_key`
  HAVING COUNT(*) > 1
) keepers
  ON keepers.`source_menu_key` = child.`source_menu_key`
WHERE child.`tenant_id` = 0
  AND child.`id` <> keepers.`keep_id`;
