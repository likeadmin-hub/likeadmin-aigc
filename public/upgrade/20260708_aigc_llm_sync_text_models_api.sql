INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES ('aigc_llm','app.aigc_llm.model/syncTextModels','POST','aigc_llm:model:save:platform','platform_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`permission_key`=VALUES(`permission_key`),
`need_login`=VALUES(`need_login`),
`need_role_permission`=VALUES(`need_role_permission`),
`status`=VALUES(`status`),
`update_time`=VALUES(`update_time`);
