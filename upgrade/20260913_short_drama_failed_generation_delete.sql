-- Failed generation history deletion: register the authenticated user API.
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES ('aigc_short_drama','app.aigc_short_drama.generation/delete','POST','aigc_short_drama:generation:delete:user','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `permission_key`=VALUES(`permission_key`),`need_login`=1,`need_role_permission`=0,`status`=1,`update_time`=VALUES(`update_time`);
