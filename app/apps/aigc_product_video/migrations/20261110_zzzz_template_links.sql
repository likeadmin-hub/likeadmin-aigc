-- Source links are import context, not reusable creation configuration.
-- Keep original project/job snapshots and every material/storage field intact.
UPDATE `la_app_case`
SET `config_json`=JSON_REMOVE(`config_json`, '$.generation_request.advanced_params.product_video.product_url'), `update_time`=UNIX_TIMESTAMP()
WHERE `app_code`='aigc_product_video' AND JSON_VALID(`config_json`)
AND JSON_CONTAINS_PATH(`config_json`, 'one', '$.generation_request.advanced_params.product_video.product_url');
UPDATE `la_app_version` SET `manifest_json`='{"code":"aigc_product_video","name":"商品视频","version":"1.0.0","require_core":">=1.0.0","description":"基于商品素材创作视频，支持商品信息识别、视频描述生成与商品视频创作。","changelog":"1. 普通模式直接描述，高级模式智能分析商品素材。\\n2. 智能分析完成后自动回填商品信息和视频描述，可修改后生成视频。\\n3. 移除分镜图片生成和相关模型配置。\\n4. 创作记录仅展示视频生成，保留完整配置并支持设为灵感模板。\\n5. 灵感模板一键同款回填完整配置，创建独立草稿。\\n6. 灵感模板只复用素材与创作设置，同款草稿不携带商品来源链接。","icon":"resource/image/common/menu_generator.png","category":"aigc","cover":"","is_builtin":1,"expire_policy":"allow","sort":846,"frontends":["platform","tenant","pc","uniapp"],"api_prefix":"/app/aigc_product_video","menus":"menus/tenant.json","permissions":"permissions/tenant.json","migrations":"migrations","dependencies":[{"app_code":"aigc_video","name":"视频创作","required_for":"生成商品视频"}],"billing":{"provider":"power_market","actions":["product","reference","plan","analysis","write","optimize","video","chapters"],"currency":"points"},"frontend_entries":[{"terminal":"pc","entry_key":"aigc_product_video","name":"商品视频","path":"/ai/tools/aigc_product_video","icon":"resource/image/common/menu_generator.png","sort":84,"status":1},{"terminal":"tenant","entry_key":"aigc_product_video_admin","name":"商品视频","path":"/apps/aigc_product_video/config","icon":"el-icon-VideoCamera","sort":84,"status":1},{"terminal":"uniapp","entry_key":"aigc_product_video","name":"商品视频","path":"/apps/aigc_product_video/pages/creator/index","icon":"resource/image/common/menu_generator.png","sort":846,"status":1,"meta":{"pages":[{"name":"创作首页","path":"/apps/aigc_product_video/pages/creator/index"},{"name":"历史创作","path":"/apps/aigc_product_video/pages/tasks/index"}]}}]}', `changelog`='1. 普通模式直接描述，高级模式智能分析商品素材。
2. 智能分析完成后自动回填商品信息和视频描述，可修改后生成视频。
3. 移除分镜图片生成和相关模型配置。
4. 创作记录仅展示视频生成，保留完整配置并支持设为灵感模板。
5. 灵感模板一键同款回填完整配置，创建独立草稿。
6. 灵感模板只复用素材与创作设置，同款草稿不携带商品来源链接。' WHERE `app_code`='aigc_product_video' AND `version`='1.0.0';
