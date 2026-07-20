DELETE FROM `la_system_menu`
WHERE `app_code` = 'aigc_video'
  AND `source` = 'app'
  AND `source_menu_key` IN (
    'aigc_video_platform_channel',
    'aigc_video_platform_spec'
  );

DELETE FROM `la_tenant_system_menu`
WHERE `app_code` = 'aigc_video'
  AND `source` = 'app'
  AND `source_menu_key` = 'aigc_video_channel_price';
