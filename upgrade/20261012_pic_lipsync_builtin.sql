-- Built-in picture digital human; preserve tenant content and historical orders.
INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`is_builtin`,`sort`,`current_version`,`status`,`expire_policy`,`install_time`,`update_time`)
VALUES ('aigc_pic_lipsync','图片数字人','resource/image/common/menu_generator.png','上传人物图片，使用音频或文案驱动生成数字人视频，支持快速、标准和 MAX 模式。提供应用开通、展示配置、素材管理、任务管理和用量统计。','aigc','','platform,tenant,pc,uniapp',1,890,'1.0.4','installed','allow',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `is_builtin`=1,`status`='installed',`expire_policy`='allow',`update_time`=UNIX_TIMESTAMP();
INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT t.id,'aigc_pic_lipsync',a.current_version,'paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM (SELECT 0 AS id UNION SELECT id FROM la_tenant) t JOIN la_app a ON a.code='aigc_pic_lipsync'
ON DUPLICATE KEY UPDATE `buy_status`='paid',`shelf_status`='on',`enable_status`='enabled',`expire_time`=0,`update_time`=UNIX_TIMESTAMP();
UPDATE `la_app_plan` SET `status`=0 WHERE `app_code`='aigc_pic_lipsync';
