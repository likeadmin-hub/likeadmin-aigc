-- Align legacy system display titles with PC entry names.
-- Custom tenant titles and all other presentation/business settings are preserved.
-- Fresh installations and new tenants use AppDisplayConfigService::PC_APP_TITLES.

UPDATE `la_tenant_app_config`
SET `title` = '图像创作', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_image' AND BINARY `title` IN ('图片生成');

UPDATE `la_tenant_app_config`
SET `title` = '视频创作', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_video' AND BINARY `title` IN ('视频生成');

UPDATE `la_tenant_app_config`
SET `title` = '音乐创作', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_music' AND BINARY `title` IN ('AI音乐生成');

UPDATE `la_tenant_app_config`
SET `title` = 'Agent对话', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_llm' AND BINARY `title` IN ('AIGC 对话', 'AIGC对话');

UPDATE `la_tenant_app_config`
SET `title` = '画布', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_canvas' AND BINARY `title` IN ('无限画布');

UPDATE `la_tenant_app_config`
SET `title` = '短剧', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_short_drama' AND BINARY `title` IN ('AI短剧');

UPDATE `la_tenant_app_config`
SET `title` = 'GEO营销', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_geo' AND BINARY `title` IN ('GEO营销优化系统');

UPDATE `la_tenant_app_config`
SET `title` = '全驱动数字人', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'image_human' AND BINARY `title` IN ('全驱数字人');

UPDATE `la_tenant_app_config`
SET `title` = '对口型数字人', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_digital_human' AND BINARY `title` IN ('数字人视频');
