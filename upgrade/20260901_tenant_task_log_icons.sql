-- Restore visual distinction for image and video task records under task logs.
UPDATE `la_tenant_system_menu`
SET `icon`='el-icon-Picture', `update_time`=UNIX_TIMESTAMP()
WHERE `source_menu_key`='aigc_image_task';

UPDATE `la_tenant_system_menu`
SET `icon`='el-icon-VideoCamera', `update_time`=UNIX_TIMESTAMP()
WHERE `source_menu_key`='aigc_video_task';
