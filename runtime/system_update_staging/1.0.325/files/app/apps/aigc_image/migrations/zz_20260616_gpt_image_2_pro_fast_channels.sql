INSERT INTO `la_aigc_image_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2_pro','GPT Image 2 Pro','gpt_image_2_pro','gpt-image-2-pro',16,'{"poll_interval":2,"poll_attempts":30,"upstream_channel":"OpenaiM","quantity_options":[1],"ratio_options":["auto","1:1","3:2","2:3","4:3","3:4","5:4","4:5","16:9","9:16","2:1","1:2","3:1","1:3","21:9","9:21"]}',1,620,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','GPT Image 2 Fast','gpt_image_2_fast','gpt-image-2-fast',16,'{"poll_interval":2,"poll_attempts":30,"upstream_channel":"openaiD","quantity_options":[1],"ratio_options":["1:1","3:2","2:3","4:3","3:4","5:4","4:5","16:9","9:16","2:1","1:2","3:1","1:3","21:9","9:21"]}',1,610,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `provider`=VALUES(`provider`),
  `model`=VALUES(`model`),
  `max_reference_images`=VALUES(`max_reference_images`),
  `config_json`=VALUES(`config_json`),
  `status`=IF(`status` IN (0,1), `status`, VALUES(`status`)),
  `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
  `update_time`=UNIX_TIMESTAMP();

UPDATE `la_app` SET `current_version`='1.1.6', `update_time`=UNIX_TIMESTAMP() WHERE `code`='aigc_image';

INSERT INTO `la_app_version` (`app_code`,`version`,`require_core`,`package_path`,`manifest_json`,`changelog`,`status`,`create_time`)
VALUES ('aigc_image','1.1.6','>=1.0.0','local','{"code":"aigc_image","name":"AIGC生图","version":"1.1.6","require_core":">=1.0.0","description":"AIGC image generation sample application for the LikeAdmin AIGC SaaS aggregation platform.","changelog":"1. 新增 GPT Image 2 Pro 和 GPT Image 2 Fast 生图模型。\n2. PC 端生图入口支持按模型选择清晰度、比例、数量和参考图。\n3. 优化新模型的点数预估和任务提交体验。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":900,"frontends":["tenant","pc","uniapp"],"api_prefix":"/app/aigc_image","platform_menus":"menus/platform.json","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","frontend_entries":[{"terminal":"tenant","entry_key":"aigc_image_admin","name":"AIGC生图","path":"/app/aigc_image","icon":"el-icon-Picture","sort":100,"status":1},{"terminal":"pc","entry_key":"aigc_image","name":"AIGC生图","path":"/app/aigc_image","icon":"resource/image/common/menu_generator.png","sort":100,"status":1},{"terminal":"uniapp","entry_key":"aigc_image","name":"AIGC生图","path":"/apps/aigc_image/pages/index/index","icon":"resource/image/common/menu_generator.png","sort":100,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_image/pages/index/index"},{"name":"生图任务","path":"/apps/aigc_image/pages/tasks/tasks"},{"name":"作品列表","path":"/apps/aigc_image/pages/results/results"}]}}]}','1. 新增 GPT Image 2 Pro 和 GPT Image 2 Fast 生图模型。
2. PC 端生图入口支持按模型选择清晰度、比例、数量和参考图。
3. 优化新模型的点数预估和任务提交体验。',1,UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `require_core`=VALUES(`require_core`),`package_path`=VALUES(`package_path`),`manifest_json`=VALUES(`manifest_json`),`changelog`=VALUES(`changelog`),`status`=VALUES(`status`);

DELETE FROM `la_aigc_image_channel_spec`
WHERE `tenant_id` = 0
  AND `channel_code` IN ('gpt_image_2_pro','gpt_image_2_fast');

INSERT INTO `la_aigc_image_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`cost_source_url`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2_pro','1k','1K','default',0,0,0.0000,30.0000,30.0000,'1K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-pro-openaim','{"image_size":"1k","omit_resolution":true}',1,1300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_pro','2k','2K','default',0,0,0.0000,60.0000,60.0000,'2K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-pro-openaim','{"image_size":"2k","omit_resolution":true}',1,1290,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_pro','4k','4K','default',0,0,0.0000,120.0000,120.0000,'4K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-pro-openaim','{"image_size":"4k","omit_resolution":true}',1,1280,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','1k','1K','default',0,0,0.0000,30.0000,30.0000,'1K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-fast-openaid','{"image_size":"1k","omit_resolution":true}',1,1270,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','2k','2K','default',0,0,0.0000,60.0000,60.0000,'2K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-fast-openaid','{"image_size":"2k","omit_resolution":true}',1,1260,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2_fast','4k','4K','default',0,0,0.0000,120.0000,120.0000,'4K 出图按分辨率计费','https://api.likeadmin.cn/user_center/docs?slug=m-gpt-image-2-fast-openaid','{"image_size":"4k","omit_resolution":true}',1,1250,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
