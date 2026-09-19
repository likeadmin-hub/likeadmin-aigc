INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_video','app.aigc_video.channel/batchSave','POST','aigc_video:channel_price:batch_save','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_video','app.aigc_video.spec/batchSave','POST','aigc_video:spec:batch_save','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_app_api`.`permission_key`=VALUES(`permission_key`),`la_app_api`.`need_login`=VALUES(`need_login`),`la_app_api`.`need_role_permission`=VALUES(`need_role_permission`),`la_app_api`.`status`=VALUES(`status`),`la_app_api`.`update_time`=VALUES(`update_time`);
