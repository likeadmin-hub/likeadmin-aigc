-- AI short drama user-facing app tables.

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧配置';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_project` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `prompt` text,
  `ratio` varchar(20) NOT NULL DEFAULT '9:16',
  `multi_episode` tinyint NOT NULL DEFAULT 0,
  `episode_count` int unsigned NOT NULL DEFAULT 1,
  `target_duration_seconds` int unsigned NOT NULL DEFAULT 0,
  `input_asset_ids` text,
  `cover_url` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `last_task_id` varchar(64) NOT NULL DEFAULT '',
  `current_version_id` int unsigned NOT NULL DEFAULT 0,
  `current_agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `timeline_json` mediumtext,
  `final_video_asset_id` int unsigned NOT NULL DEFAULT 0,
  `publish_id` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_status_update` (`tenant_id`,`status`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧项目';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_script_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT '统一应用任务ID',
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `parent_task_id` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `current_step` varchar(120) NOT NULL DEFAULT '',
  `prompt` text,
  `request_json` mediumtext,
  `config_snapshot` text,
  `pricing_snapshot` text,
  `result_json` mediumtext,
  `error` varchar(500) NOT NULL DEFAULT '',
  `operator_error` text,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `provider_request_id` varchar(100) NOT NULL DEFAULT '',
  `provider_task_id` varchar(100) NOT NULL DEFAULT '',
  `idempotency_key` varchar(100) NOT NULL DEFAULT '',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`task_id`),
  KEY `idx_app_task` (`app_task_id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_project` (`tenant_id`,`project_id`),
  KEY `idx_status` (`tenant_id`,`status`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧剧本策划任务';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_storyboard` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `shot_id` varchar(40) NOT NULL DEFAULT '',
  `act` varchar(160) NOT NULL DEFAULT '',
  `title` varchar(120) NOT NULL DEFAULT '',
  `scene_name` varchar(100) NOT NULL DEFAULT '',
  `time_of_day` varchar(40) NOT NULL DEFAULT '',
  `interior_exterior` varchar(20) NOT NULL DEFAULT 'exterior',
  `visual_description` text,
  `composition` varchar(500) NOT NULL DEFAULT '',
  `camera_movement` varchar(500) NOT NULL DEFAULT '',
  `shot_type` varchar(80) NOT NULL DEFAULT '',
  `angle` varchar(80) NOT NULL DEFAULT '',
  `action` text,
  `result` varchar(500) NOT NULL DEFAULT '',
  `atmosphere` varchar(300) NOT NULL DEFAULT '',
  `image_prompt` text,
  `video_prompt` text,
  `bgm_prompt` varchar(500) NOT NULL DEFAULT '',
  `sound_effect` varchar(500) NOT NULL DEFAULT '',
  `scene_ref_id` varchar(80) NOT NULL DEFAULT '',
  `subject_ref_ids` text,
  `selected_image_asset_id` int unsigned NOT NULL DEFAULT 0,
  `selected_video_asset_id` int unsigned NOT NULL DEFAULT 0,
  `voice_role` varchar(100) NOT NULL DEFAULT '',
  `dialogue` text,
  `frame_type` varchar(20) NOT NULL DEFAULT 'normal',
  `recommended_duration_seconds` decimal(5,2) NOT NULL DEFAULT 3.00,
  `sort` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_shot` (`tenant_id`,`task_id`,`shot_id`),
  KEY `idx_task_sort` (`tenant_id`,`task_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧分镜';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_subject` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(80) NOT NULL DEFAULT '',
  `image` varchar(500) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `category` varchar(40) NOT NULL DEFAULT 'character',
  `gender` varchar(20) NOT NULL DEFAULT 'unknown',
  `age_stage` varchar(30) NOT NULL DEFAULT 'unknown',
  `source` varchar(20) NOT NULL DEFAULT 'public',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_source` (`tenant_id`,`source`,`status`,`sort`),
  KEY `idx_subject_filters` (`tenant_id`,`category`,`gender`,`age_stage`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧主体库';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_style` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(80) NOT NULL DEFAULT '',
  `image` varchar(500) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `is_new` tinyint NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧风格库';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_inspiration` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `video_url` varchar(500) NOT NULL DEFAULT '',
  `cover_url` varchar(500) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` decimal(8,2) NOT NULL DEFAULT 0.00,
  `prompt` text,
  `author_json` text,
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧灵感广场';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_agent_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `run_type` varchar(40) NOT NULL DEFAULT 'initial_plan',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `input_summary` varchar(500) NOT NULL DEFAULT '',
  `request_json` mediumtext,
  `output_summary` varchar(500) NOT NULL DEFAULT '',
  `output_version_id` int unsigned NOT NULL DEFAULT 0,
  `model_json` text,
  `error_code` varchar(80) NOT NULL DEFAULT '',
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_run` (`tenant_id`,`agent_run_id`),
  KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Agent运行记录';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_agent_step_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `step_key` varchar(80) NOT NULL DEFAULT '',
  `step_name` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `input_json` mediumtext,
  `output_json` mediumtext,
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `sort` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_run_sort` (`tenant_id`,`agent_run_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Agent步骤日志';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_plan_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `parent_version_id` int unsigned NOT NULL DEFAULT 0,
  `version_no` int unsigned NOT NULL DEFAULT 1,
  `version_type` varchar(40) NOT NULL DEFAULT 'agent_initial',
  `title` varchar(120) NOT NULL DEFAULT '',
  `story_bible_json` mediumtext,
  `continuity_json` mediumtext,
  `plan_json` mediumtext,
  `storyboard_json` mediumtext,
  `is_current` tinyint NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project_version` (`tenant_id`,`project_id`,`version_no`,`delete_time`),
  KEY `idx_current` (`tenant_id`,`project_id`,`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧策划版本';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_asset` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `shot_id` varchar(40) NOT NULL DEFAULT '',
  `asset_type` varchar(40) NOT NULL DEFAULT 'reference_image',
  `title` varchar(120) NOT NULL DEFAULT '',
  `uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(30) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(120) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` decimal(8,2) NOT NULL DEFAULT 0.00,
  `checksum` varchar(100) NOT NULL DEFAULT '',
  `meta_json` text,
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project_type` (`tenant_id`,`project_id`,`asset_type`,`delete_time`),
  KEY `idx_task` (`tenant_id`,`task_id`,`shot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧项目资产';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_generation_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `shot_id` varchar(40) NOT NULL DEFAULT '',
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `parent_task_id` varchar(64) NOT NULL DEFAULT '',
  `source_task_id` varchar(64) NOT NULL DEFAULT '',
  `source_app_code` varchar(40) NOT NULL DEFAULT '',
  `task_type` varchar(40) NOT NULL DEFAULT 'shot_image',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `provider` varchar(50) NOT NULL DEFAULT 'pending',
  `provider_task_id` varchar(100) NOT NULL DEFAULT '',
  `provider_request_id` varchar(100) NOT NULL DEFAULT '',
  `model_json` text,
  `request_json` mediumtext,
  `result_json` mediumtext,
  `input_asset_ids` text,
  `output_asset_ids` text,
  `pricing_snapshot` text,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `idempotency_key` varchar(100) NOT NULL DEFAULT '',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(80) NOT NULL DEFAULT '',
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `operator_error` text,
  `safety_status` varchar(30) NOT NULL DEFAULT 'pending',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`tenant_id`,`task_id`),
  KEY `idx_project_type` (`tenant_id`,`project_id`,`task_type`,`status`),
  KEY `idx_shot` (`tenant_id`,`project_id`,`shot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧生成任务';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_published_work` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `final_video_asset_id` int unsigned NOT NULL DEFAULT 0,
  `cover_asset_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `intro` varchar(500) NOT NULL DEFAULT '',
  `script_description` text,
  `social_link` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `activity_tags_json` text,
  `audit_status` varchar(30) NOT NULL DEFAULT 'reviewing',
  `audit_reason` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 0,
  `submitted_at` int unsigned NOT NULL DEFAULT 0,
  `audited_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`),
  KEY `idx_audit` (`tenant_id`,`audit_status`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧发布作品';

INSERT INTO `la_aigc_short_drama_config`
(`tenant_id`, `config_json`, `status`, `create_time`, `update_time`)
SELECT 0, '{"script_plan_points":0,"background":{"type":"video","items":[{"url":"https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/video/20260702/20260702030949da9924540.mp4","poster_url":""},{"url":"https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/video/20260601/20260601112012e02b49571.mov","poster_url":""}]},"ratios":[{"label":"9:16","width":9,"height":16},{"label":"16:9","width":16,"height":9},{"label":"3:4","width":3,"height":4},{"label":"4:3","width":4,"height":3},{"label":"21:9","width":21,"height":9},{"label":"1:1","width":1,"height":1}],"models":[{"id":"script-planner-default","name":"剧本策划模型","description":"用于故事扩写、剧本策划与分镜文本生成","image":"resource/image/common/menu_generator.png","enabled":true,"sort":10}]}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_short_drama_config` WHERE `tenant_id` = 0);

-- Seed default AI short drama style library from backend-cleaned style fields.
INSERT INTO `la_aigc_short_drama_style`
(`tenant_id`, `name`, `image`, `description`, `is_new`, `status`, `sort`, `create_time`, `update_time`, `delete_time`)
SELECT 0, seed.`name`, seed.`image`, seed.`description`, seed.`is_new`, seed.`status`, seed.`sort`, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0
FROM (
    SELECT '复古科幻原子朋克' AS `name`, 'static/aigc_short_drama/style_library/001_1780651424491.webp' AS `image`, '60年代复古科幻原子朋克美学，复古未来主义影像风格，真人写实风格，摄影作品，画面具有60年代复古科幻质感，色调以复古暖橙、海盐蓝、高对比低饱和胶片色彩为主，带明显胶片颗粒、轻微复古胶片柔光和自然日光光晕。光影上使用自然日光、强直射日光和清晰投影，画面明暗对比强烈，高光不过曝，暗部保留完整细节，明暗过渡自然，整体呈现自然立体、怀旧、精致的原子朋克电影感。' AS `description`, 0 AS `is_new`, 1 AS `status`, 1 AS `sort`
    UNION ALL SELECT '宫斗权谋冷峻风格' AS `name`, 'static/aigc_short_drama/style_library/002_1780580870934.webp' AS `image`, '宫廷权谋剧影像风格，古装宫斗冷峻摄影风格，真人写实风格，摄影作品，画面庄重克制，空间秩序感强，色调以低饱和金棕、暗红、冷灰、深木色为主，光影上使用低调照明、烛光感、侧光和深阴影，构图端正严整，整体具有宫廷权力压迫感和古装权谋剧质感。' AS `description`, 0 AS `is_new`, 1 AS `status`, 2 AS `sort`
    UNION ALL SELECT '国产悬疑冷调' AS `name`, 'static/aigc_short_drama/style_library/003_1780575862644.webp' AS `image`, '国产现实悬疑剧影像风格，冷调现实主义摄影风格，真人写实风格，摄影作品，整体氛围紧张压抑，色调以低饱和、冷灰、暗绿色调为主，光影上使用低调照明、环境光、深阴影，构图克制，画面具有真实城市悬疑剧质感。' AS `description`, 0 AS `is_new`, 1 AS `status`, 3 AS `sort`
    UNION ALL SELECT '古偶唯美柔光' AS `name`, 'static/aigc_short_drama/style_library/004_1780575862643.webp' AS `image`, '精品古装偶像剧影像风格，古偶剧柔光摄影风格，真人写实风格，摄影作品，画面柔和唯美，色调以暖白、淡金、浅青、低饱和粉色为主，光影上使用柔光、逆光、轻微辉光、浅景深，构图端正精致，整体具有古装爱情剧的精致梦幻感。' AS `description`, 0 AS `is_new`, 1 AS `status`, 4 AS `sort`
    UNION ALL SELECT '日式青春胶片' AS `name`, 'static/aigc_short_drama/style_library/005_1780575862642.webp' AS `image`, '参考电影为《情书》，岩井俊二式日式青春电影影像风格，90年代日式胶片摄影风格，真人写实风格，摄影作品，画面清透、柔和、带有回忆感，具有明显胶片颗粒、轻微噪点、柔焦质感和低对比影调，色调以低饱和、冷白、淡蓝、浅绿、柔灰为主，光影上使用自然光、逆光、窗光、轻微过曝、柔和高光和空气感眩光，构图轻盈留白，带有手持摄影般的生活化瞬间感，整体氛围纯净、忧伤、朦胧、诗意。' AS `description`, 0 AS `is_new`, 1 AS `status`, 5 AS `sort`
    UNION ALL SELECT '日式生活自然' AS `name`, 'static/aigc_short_drama/style_library/006_1780575862641.webp' AS `image`, '日式生活剧影像风格，自然主义摄影风格，真人写实风格，摄影作品，真实生活质感，画面安静克制，构图留白，色调以低饱和、淡青灰、冷绿色调为主，光影上使用自然光、窗光、环境光、柔和阴影，强调真实空间和日常气息。' AS `description`, 0 AS `is_new`, 1 AS `status`, 6 AS `sort`
    UNION ALL SELECT '韩剧都市柔光' AS `name`, 'static/aigc_short_drama/style_library/007_1780575862640.webp' AS `image`, '韩国都市爱情剧影像风格，韩剧柔光摄影风格，真人写实风格，摄影作品，画面干净精致，色调以低饱和、柔和冷暖平衡为主，光影上使用柔光、环境光、浅景深，整体氛围细腻、浪漫、克制。' AS `description`, 0 AS `is_new`, 1 AS `status`, 7 AS `sort`
    UNION ALL SELECT '国产都市写实' AS `name`, 'static/aigc_short_drama/style_library/008_1780575862639.webp' AS `image`, '现代国产都市电视剧影像风格，生活化都市剧摄影风格，真人写实风格，摄影作品，画面自然克制，真实生活质感，色调以中性暖调、低饱和为主，光影上使用自然光、柔和室内光，构图规整，整体质感接近国产现实题材都市剧。' AS `description`, 0 AS `is_new`, 1 AS `status`, 8 AS `sort`
    UNION ALL SELECT '武侠江湖写实摄影风格' AS `name`, 'static/aigc_short_drama/style_library/009_1780580870935.webp' AS `image`, '武侠江湖剧影像风格，古装动作写实摄影风格，真人写实风格，摄影作品，画面具有江湖气和动作感，色调以低饱和青灰、墨绿、土黄、冷暖对比为主，光影上使用自然光、侧光、逆光和环境阴影，构图开阔有纵深，粗粝真实的武侠剧质感。' AS `description`, 0 AS `is_new`, 1 AS `status`, 9 AS `sort`
    UNION ALL SELECT '90年代写实电影风格' AS `name`, 'static/aigc_short_drama/style_library/010_1779094923802.webp' AS `image`, '1990s realistic cinematic film photography style，1990s，真人写实风格，摄影作品。' AS `description`, 0 AS `is_new`, 1 AS `status`, 10 AS `sort`
    UNION ALL SELECT '复古叙事电影风格' AS `name`, 'static/aigc_short_drama/style_library/011_1779094923757.webp' AS `image`, '参考电影为《Titane》，真人写实风格，摄影作品，整体风格包含混乱感，色调以低调、暖调、黄色辉光、冷蓝光为主，光影上使用低调照明。' AS `description`, 0 AS `is_new`, 1 AS `status`, 11 AS `sort`
    UNION ALL SELECT '美式复古好莱坞' AS `name`, 'static/aigc_short_drama/style_library/012_1779094923765.webp' AS `image`, '参考电影为《Demons》，真人写实风格，摄影作品，整体风格包含复古，色调以低调、去饱和为主，光影上使用柔光。' AS `description`, 0 AS `is_new`, 1 AS `status`, 12 AS `sort`
    UNION ALL SELECT '霓虹赛博电影风格' AS `name`, 'static/aigc_short_drama/style_library/013_1779094923777.webp' AS `image`, '参考电影为《Mind》，真人写实风格，摄影作品，整体风格包含粗粝、复古，色调以低调、霓虹色调、暖调为主，光影上使用逆光、轮廓光。' AS `description`, 0 AS `is_new`, 1 AS `status`, 13 AS `sort`
    UNION ALL SELECT '90 年代中国农村电影风格' AS `name`, 'static/aigc_short_drama/style_library/014_1779094923813.webp' AS `image`, '90s Chinese rural cinematic style，真人写实风格，摄影作品。' AS `description`, 0 AS `is_new`, 1 AS `status`, 14 AS `sort`
    UNION ALL SELECT '中式暖调蓝辉风格' AS `name`, 'static/aigc_short_drama/style_library/015_1779094923774.webp' AS `image`, '参考电影为《OneHourPhoto》，真人写实风格，摄影作品，色调以暖调和蓝辉光为主，营造出戏剧性的明暗对比。' AS `description`, 0 AS `is_new`, 1 AS `status`, 15 AS `sort`
    UNION ALL SELECT '老式工业影视风格' AS `name`, 'static/aigc_short_drama/style_library/016_1779094923776.webp' AS `image`, '参考电影为《NineteenEighty-Four》，真人写实风格，摄影作品，整体风格包含复古、工业感，色调以低调、微弱amber辉光为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 16 AS `sort`
    UNION ALL SELECT '日本黑白胶片摄影风格' AS `name`, 'static/aigc_short_drama/style_library/017_1779094923794.webp' AS `image`, 'Japanese black and white film photography style，black and white，真人写实风格，摄影作品。' AS `description`, 0 AS `is_new`, 1 AS `status`, 17 AS `sort`
    UNION ALL SELECT '韩国冷淡风电影风格' AS `name`, 'static/aigc_short_drama/style_library/018_1779094923778.webp' AS `image`, '参考电影为《MemoriesMurder》，真人写实风格，摄影作品，整体风格包含紧张、粗粝，色调以去饱和、低饱和柔和色调、低调为主，光影上使用环境光、柔和阴影。' AS `description`, 0 AS `is_new`, 1 AS `status`, 18 AS `sort`
    UNION ALL SELECT '荒野电影风格' AS `name`, 'static/aigc_short_drama/style_library/019_1779094923775.webp' AS `image`, '参考电影为《OnceUponTime在West》，真人写实风格，摄影作品，整体风格包含紧张，色调以低饱和、棕色色调、单色调、暖调为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 19 AS `sort`
    UNION ALL SELECT '橙黄色电影风格' AS `name`, 'static/aigc_short_drama/style_library/020_1779094923779.webp' AS `image`, '参考电影为《Kubi》，真人写实风格，摄影作品，整体风格包含紧张、混乱感，色调以橙黄色为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 20 AS `sort`
    UNION ALL SELECT '复古战争电影风格' AS `name`, 'static/aigc_short_drama/style_library/021_1779094923782.webp' AS `image`, '参考电影为《300》，真人写实风格，摄影作品，整体风格包含粗粝，色调以高对比、低调、去饱和、单色调为主，光影上使用主光、逆光、戏剧性阴影、伦勃朗式布光。' AS `description`, 0 AS `is_new`, 1 AS `status`, 21 AS `sort`
    UNION ALL SELECT '恐怖电影风格' AS `name`, 'static/aigc_short_drama/style_library/022_1779094923781.webp' AS `image`, '参考电影为《AutopsyJaneDoe》，真人写实风格，摄影作品，整体风格包含紧张，色调以单色调、肉色调为主，光影上使用戏剧性阴影。' AS `description`, 0 AS `is_new`, 1 AS `status`, 22 AS `sort`
    UNION ALL SELECT '复古电影摄影风格' AS `name`, 'static/aigc_short_drama/style_library/023_1779094923795.webp' AS `image`, 'Retro film photography style，真人写实风格，摄影作品，Retro，人物肖像，摄影作品。' AS `description`, 0 AS `is_new`, 1 AS `status`, 23 AS `sort`
    UNION ALL SELECT '美式复古怪异影视风格' AS `name`, 'static/aigc_short_drama/style_library/024_1779094923780.webp' AS `image`, '参考电影为《HumanHighway》，真人写实风格，摄影作品，整体风格包含怪异、复古、超现实主义、末世感，色调以单色调、高饱和、霓虹色调为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 24 AS `sort`
    UNION ALL SELECT '荒诞高调白色色调电影风格' AS `name`, 'static/aigc_short_drama/style_library/025_1779094923773.webp' AS `image`, '参考电影为《SlackBay》，真人写实风格，摄影作品，整体风格包含怪诞、超现实、黑色幽默，色调以high-key、高饱和为主，光影上使用natural light。' AS `description`, 0 AS `is_new`, 1 AS `status`, 25 AS `sort`
    UNION ALL SELECT '高品质动画渲染风格' AS `name`, 'static/aigc_short_drama/style_library/026_1779094923823.webp' AS `image`, '这是一张3D渲染风格（影视级CG概念艺术风格）图片，3DCG渲染风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 26 AS `sort`
    UNION ALL SELECT '3D风格化渲染' AS `name`, 'static/aigc_short_drama/style_library/027_1779094923846.webp' AS `image`, '3D角色（风格化渲染），3D风格化，展示了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 27 AS `sort`
    UNION ALL SELECT '蓝橙色调影视风格' AS `name`, 'static/aigc_short_drama/style_library/028_1779094923768.webp' AS `image`, '参考电影为《Wanted》，真人写实风格，摄影作品，色调以蓝色调、暖调为主，光影上使用低调照明。' AS `description`, 0 AS `is_new`, 1 AS `status`, 28 AS `sort`
    UNION ALL SELECT '工业电影风格' AS `name`, 'static/aigc_short_drama/style_library/029_1779094923772.webp' AS `image`, '参考电影为《Substance》，真人写实风格，摄影作品，人物肖像，色调以高饱和Monochromatic、高调、白色调为主，光影上使用Key Light。' AS `description`, 0 AS `is_new`, 1 AS `status`, 29 AS `sort`
    UNION ALL SELECT '美式经济上行风格' AS `name`, 'static/aigc_short_drama/style_library/030_1779094923760.webp' AS `image`, '参考电影为《Pain&Gain》，真人写实风格，摄影作品，色调以高饱和为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 30 AS `sort`
    UNION ALL SELECT '90年代港片风格' AS `name`, 'static/aigc_short_drama/style_library/031_1779094923767.webp' AS `image`, '参考电影为《FlowersShanghai》，色调以暖调、黄色辉光为主，真人写实风格，摄影作品，色调以暖调、黄色辉光为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 31 AS `sort`
    UNION ALL SELECT '科技感电影风格' AS `name`, 'static/aigc_short_drama/style_library/032_1779094923770.webp' AS `image`, '参考电影为《TrueLies》，真人写实风格，摄影作品，人物肖像，整体风格包含紧张，色调以低调、冷调为主，光影上使用主光。' AS `description`, 0 AS `is_new`, 1 AS `status`, 32 AS `sort`
    UNION ALL SELECT '悬疑电影风格' AS `name`, 'static/aigc_short_drama/style_library/033_1779094923761.webp' AS `image`, '参考电影为《Nun》，真人写实风格，摄影作品，色调以去饱和为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 33 AS `sort`
    UNION ALL SELECT '希腊神话电影风格' AS `name`, 'static/aigc_short_drama/style_library/034_1779094923764.webp' AS `image`, '参考电影为《FlashGordon》，真人写实风格，摄影作品，整体风格包含超现实，色调以柔和蓝辉光为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 34 AS `sort`
    UNION ALL SELECT '美式复古影视风格' AS `name`, 'static/aigc_short_drama/style_library/035_1779094923771.webp' AS `image`, '参考电影为《Tron》，真人写实风格，摄影作品，整体风格包含复古，色调以低调、高饱和、霓虹色调为主，光影上使用轮廓光。' AS `description`, 0 AS `is_new`, 1 AS `status`, 35 AS `sort`
    UNION ALL SELECT '好莱坞黑白电影风格' AS `name`, 'static/aigc_short_drama/style_library/036_1779094923766.webp' AS `image`, '参考电影为《男人WhoWasn》，真人写实风格，摄影作品，色调以高对比、单色调、低调为主，光影上使用伦勃朗式布光、深阴影。' AS `description`, 0 AS `is_new`, 1 AS `status`, 36 AS `sort`
    UNION ALL SELECT '3D卡通微缩景观' AS `name`, 'static/aigc_short_drama/style_library/037_1779094923850.webp' AS `image`, '3D渲染风格（卡通微缩景观）的单个场景，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 37 AS `sort`
    UNION ALL SELECT '3D西方卡通风格的绘制' AS `name`, 'static/aigc_short_drama/style_library/038_1779094923826.webp' AS `image`, '美式奇幻插画风格，3D西方卡通风格的绘制，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 38 AS `sort`
    UNION ALL SELECT '欧美风格化3D渲染' AS `name`, 'static/aigc_short_drama/style_library/039_1779094923843.webp' AS `image`, '3D角色（欧美风格化3D渲染），欧美风格化3D渲染风格，展示了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 39 AS `sort`
    UNION ALL SELECT '3D数字雕刻风格' AS `name`, 'static/aigc_short_drama/style_library/040_1779094923806.webp' AS `image`, '3D 渲染的角色（具有电影般的写实风格），3D高精渲染风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 40 AS `sort`
    UNION ALL SELECT '二次元概念艺术风格' AS `name`, 'static/aigc_short_drama/style_library/041_1779094923784.webp' AS `image`, '2D anime character (concept art style），2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 41 AS `sort`
    UNION ALL SELECT '3D国风高清渲染风格' AS `name`, 'static/aigc_short_drama/style_library/042_1779094923812.webp' AS `image`, '该图片展示了一幅3D高清渲染风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 42 AS `sort`
    UNION ALL SELECT '紫色色调电影风格' AS `name`, 'static/aigc_short_drama/style_library/043_1779094923758.webp' AS `image`, '参考电影为《Thin红Line》，真人写实风格，摄影作品，色调以紫色色调为主。' AS `description`, 0 AS `is_new`, 1 AS `status`, 43 AS `sort`
    UNION ALL SELECT '3D美式卡通游戏美术' AS `name`, 'static/aigc_short_drama/style_library/044_1779094923847.webp' AS `image`, '3D卡通渲染风格，3D渲染风格，3D拟人化，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 44 AS `sort`
    UNION ALL SELECT '3D盲盒涂装风' AS `name`, 'static/aigc_short_drama/style_library/045_1779094923841.webp' AS `image`, '3D盲盒涂装风、皮克斯3D动画风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 45 AS `sort`
    UNION ALL SELECT '动漫三渲二风格' AS `name`, 'static/aigc_short_drama/style_library/046_1779094923845.webp' AS `image`, '三渲二二次元卡通风格，3D 立体卡通风格绘制，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 46 AS `sort`
    UNION ALL SELECT '超现实3D渲染风格' AS `name`, 'static/aigc_short_drama/style_library/047_1779094923801.webp' AS `image`, '超现实3D渲染风格，高清写实渲染，3D建模，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 47 AS `sort`
    UNION ALL SELECT 'UE5写实渲染' AS `name`, 'static/aigc_short_drama/style_library/048_1779094923842.webp' AS `image`, '写实CG渲染，3D高清渲染，画面展示了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 48 AS `sort`
    UNION ALL SELECT '国风3D高清渲染风格' AS `name`, 'static/aigc_short_drama/style_library/049_1779094923848.webp' AS `image`, '国漫3D渲染风格，高清3D渲染，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 49 AS `sort`
    UNION ALL SELECT '3D卡通渲染风格' AS `name`, 'static/aigc_short_drama/style_library/050_1779094923849.webp' AS `image`, '皮克斯的 3D 动画风格，该画面呈现了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 50 AS `sort`
    UNION ALL SELECT '美国卡通3D渲染风格' AS `name`, 'static/aigc_short_drama/style_library/051_1779094923821.webp' AS `image`, '3D 渲染的奇幻风格，图片中，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 51 AS `sort`
    UNION ALL SELECT '3D厚涂风格' AS `name`, 'static/aigc_short_drama/style_library/052_1779094923822.webp' AS `image`, '3D渲染风格，原画CG风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 52 AS `sort`
    UNION ALL SELECT '3D真实感PBR渲染风格' AS `name`, 'static/aigc_short_drama/style_library/053_1779094923827.webp' AS `image`, '这是一张3D渲染风格（写实PBR渲染）风格图片，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 53 AS `sort`
    UNION ALL SELECT '3D游戏渲染风格' AS `name`, 'static/aigc_short_drama/style_library/054_1779094923844.webp' AS `image`, '3D游戏渲染风格，3D高清渲染，画面展示了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 54 AS `sort`
    UNION ALL SELECT '3D人物(简约卡通风）' AS `name`, 'static/aigc_short_drama/style_library/055_1779094923825.webp' AS `image`, '3D角色（美式卡通3D渲染风格），图片展示了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 55 AS `sort`
    UNION ALL SELECT '3D卡通动画风格' AS `name`, 'static/aigc_short_drama/style_library/056_1779094923803.webp' AS `image`, '图片展示了3D卡通渲染风格。3D卡通，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 56 AS `sort`
    UNION ALL SELECT '美国游戏概念艺术风格' AS `name`, 'static/aigc_short_drama/style_library/057_1779094923816.webp' AS `image`, '2D anime character (American game concept art style), Overwatch fan art style，美国游戏概念艺术风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 57 AS `sort`
    UNION ALL SELECT '复古Y2K奇幻风格' AS `name`, 'static/aigc_short_drama/style_library/058_1779094923790.webp' AS `image`, 'Extrasensory world portraiture, Y2K retro surrealist style，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 58 AS `sort`
    UNION ALL SELECT '日系平涂插画风格' AS `name`, 'static/aigc_short_drama/style_library/059_1779094923834.webp' AS `image`, '日系赛璐珞，日系平涂插画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 59 AS `sort`
    UNION ALL SELECT '插画蒸汽波风格' AS `name`, 'static/aigc_short_drama/style_library/060_1779094923838.webp' AS `image`, '80年代复古动漫插画蒸汽波风格，插画蒸汽波风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 60 AS `sort`
    UNION ALL SELECT '3D魔幻角色扮演游戏' AS `name`, 'static/aigc_short_drama/style_library/061_1779094923808.webp' AS `image`, '暗黑哥特风写实CG渲染，高清3D渲染，描绘了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 61 AS `sort`
    UNION ALL SELECT '赛博朋克数字插画风格' AS `name`, 'static/aigc_short_drama/style_library/062_1779094923836.webp' AS `image`, '赛博朋克数字插画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 62 AS `sort`
    UNION ALL SELECT '游戏概念艺术风格' AS `name`, 'static/aigc_short_drama/style_library/063_1779094923817.webp' AS `image`, '2D anime-style character (game concept art style），2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 63 AS `sort`
    UNION ALL SELECT '国漫二次元常用风格' AS `name`, 'static/aigc_short_drama/style_library/064_1779094923828.webp' AS `image`, '二次元动漫风格（百妖谱风格），画面展示了，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 64 AS `sort`
    UNION ALL SELECT '粘土动画风格' AS `name`, 'static/aigc_short_drama/style_library/065_1779094923799.webp' AS `image`, '粘土动画风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 65 AS `sort`
    UNION ALL SELECT '3D加强版卡通渲染风格' AS `name`, 'static/aigc_short_drama/style_library/066_1779094923807.webp' AS `image`, '3D卡通渲染风格，整体具有平滑细腻的3D建模质感，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 66 AS `sort`
    UNION ALL SELECT '3D中国奇幻动画' AS `name`, 'static/aigc_short_drama/style_library/067_1779094923805.webp' AS `image`, '3D渲染风格，画面展示，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 67 AS `sort`
    UNION ALL SELECT '3D光泽乳胶渲染风格' AS `name`, 'static/aigc_short_drama/style_library/068_1779094923810.webp' AS `image`, '3D角色（高清渲染风格），3D乳胶渲染风格，展示了，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 68 AS `sort`
    UNION ALL SELECT '3D果冻状塑料风格' AS `name`, 'static/aigc_short_drama/style_library/069_1779094923811.webp' AS `image`, '3D Jelly-like plastic style，3D，渐变天空。' AS `description`, 0 AS `is_new`, 1 AS `status`, 69 AS `sort`
    UNION ALL SELECT '达利风格' AS `name`, 'static/aigc_short_drama/style_library/070_1779094923833.webp' AS `image`, '萨尔瓦多·达利风格的超现实主义写实风格，画面展示了，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 70 AS `sort`
    UNION ALL SELECT '定格动画风格' AS `name`, 'static/aigc_short_drama/style_library/071_1779094923798.webp' AS `image`, '定格动画风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 71 AS `sort`
    UNION ALL SELECT '黑白水墨风格' AS `name`, 'static/aigc_short_drama/style_library/072_1779094923792.webp' AS `image`, 'Ghost of Tsushima black and white ink conceptual illustration style，黑白水墨风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 72 AS `sort`
    UNION ALL SELECT '3D西方卡通风格' AS `name`, 'static/aigc_short_drama/style_library/073_1779094923809.webp' AS `image`, '图片展示了3D卡通渲染风格，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 73 AS `sort`
    UNION ALL SELECT '高清3D真实渲染风格' AS `name`, 'static/aigc_short_drama/style_library/074_1779094923793.webp' AS `image`, 'High-definition 3D realistic rendering style，3D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 74 AS `sort`
    UNION ALL SELECT '美国漫画动画插画风格' AS `name`, 'static/aigc_short_drama/style_library/075_1779094923814.webp' AS `image`, 'American comic animation illustration style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 75 AS `sort`
    UNION ALL SELECT '复古肌理迷幻插画风格' AS `name`, 'static/aigc_short_drama/style_library/076_1779094923840.webp' AS `image`, '复古肌理迷幻插画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 76 AS `sort`
    UNION ALL SELECT '儿童蜡笔手绘插画风' AS `name`, 'static/aigc_short_drama/style_library/077_1779094923839.webp' AS `image`, '西方扁平插画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 77 AS `sort`
    UNION ALL SELECT '黑白二维漫画动画风格' AS `name`, 'static/aigc_short_drama/style_library/078_1779094923815.webp' AS `image`, 'Black and white 2D graphic novel animation style (Persepolis IP style），黑白二维漫画动画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 78 AS `sort`
    UNION ALL SELECT '高质量2D热血漫风格' AS `name`, 'static/aigc_short_drama/style_library/079_1779094923835.webp' AS `image`, '高质量2D热血漫风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 79 AS `sort`
    UNION ALL SELECT '大友克洋风格' AS `name`, 'static/aigc_short_drama/style_library/080_1779094923831.webp' AS `image`, '经典的大友克洋（Akira）二次元动漫风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 80 AS `sort`
    UNION ALL SELECT '暗色调厚涂风格插画' AS `name`, 'static/aigc_short_drama/style_library/081_1779094923818.webp' AS `image`, '2D Hand-drawn Character (Dark Impasto Illustration Style），2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 81 AS `sort`
    UNION ALL SELECT '手冢治虫时代卡通画风' AS `name`, 'static/aigc_short_drama/style_library/082_1779094923830.webp' AS `image`, '手冢治虫时代卡通画风，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 82 AS `sort`
    UNION ALL SELECT '上美画风' AS `name`, 'static/aigc_short_drama/style_library/083_1779094923832.webp' AS `image`, '二次元动漫风格（上海美术电影制片厂风格），2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 83 AS `sort`
    UNION ALL SELECT '中国神话风格' AS `name`, 'static/aigc_short_drama/style_library/084_1779094923819.webp' AS `image`, '2D hand-drawn character, Chinese mythology style，中国神话风格，描金线条插画，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 84 AS `sort`
    UNION ALL SELECT '二维卡通插画风格' AS `name`, 'static/aigc_short_drama/style_library/085_1779094923800.webp' AS `image`, 'Stylized 2D cartoon illustration style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 85 AS `sort`
    UNION ALL SELECT '黑暗原画概念风格' AS `name`, 'static/aigc_short_drama/style_library/086_1779094923786.webp' AS `image`, 'Black Myth Wukong style, dark fantasy original art thick impasto style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 86 AS `sort`
    UNION ALL SELECT '皮影戏插画风格' AS `name`, 'static/aigc_short_drama/style_library/087_1779094923797.webp' AS `image`, 'Shadow puppetry illustration style，皮影戏插画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 87 AS `sort`
    UNION ALL SELECT '美式黑暗插画风格' AS `name`, 'static/aigc_short_drama/style_library/088_1779094923788.webp' AS `image`, '美国漫画风格，欧美漫画风格，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 88 AS `sort`
    UNION ALL SELECT '黑暗奇幻插画风格' AS `name`, 'static/aigc_short_drama/style_library/089_1779094923787.webp' AS `image`, '黑暗奇幻插画风格，美式复古原画风格，画面展示了，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 89 AS `sort`
    UNION ALL SELECT '暗黑漫画风格' AS `name`, 'static/aigc_short_drama/style_library/090_1779094923785.webp' AS `image`, '2D anime character (dark manga style），2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 90 AS `sort`
    UNION ALL SELECT '简洁插画风格' AS `name`, 'static/aigc_short_drama/style_library/091_1779094923791.webp' AS `image`, 'Flat modern illustration style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 91 AS `sort`
    UNION ALL SELECT '复古半色调暗色调哥特风格' AS `name`, 'static/aigc_short_drama/style_library/092_1779094923796.webp' AS `image`, 'Retro halftone dark gothic illustration style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 92 AS `sort`
    UNION ALL SELECT '东方水墨画风' AS `name`, 'static/aigc_short_drama/style_library/093_1779094923789.webp' AS `image`, 'Eastern ink decorative illustration style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 93 AS `sort`
    UNION ALL SELECT '像素风' AS `name`, 'static/aigc_short_drama/style_library/094_1779094923783.webp' AS `image`, '16-bit retro pixel art style，2D。' AS `description`, 0 AS `is_new`, 1 AS `status`, 94 AS `sort`
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `la_aigc_short_drama_style` existing
    WHERE existing.`tenant_id` = 0 AND existing.`name` = seed.`name` AND existing.`delete_time` = 0
);

INSERT INTO `la_aigc_short_drama_inspiration`
(`tenant_id`, `title`, `video_url`, `cover_url`, `width`, `height`, `duration`, `prompt`, `author_json`, `config_json`, `status`, `sort`, `create_time`, `update_time`, `delete_time`)
SELECT 0, '冬日河畔的静默', 'https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/video/20260702/20260702030949da9924540.mp4', '', 1080, 1920, 8.20, '创造安妮贝尔。一位来自中国的国际学生，身处纽约州上州的一所寄宿学校。她安静地在码头等一名划船手科尔。黄昏、河水、围巾、书本、校园台阶。', '{"id":1,"nickname":"岩井俊二电影","avatar":"resource/image/common/menu_generator.png"}', '{"ratio":"9:16","multi_episode":true,"style_id":"1","style_name":"岩井俊二电影","model_id":"script-planner-default","model_name":"剧本策划模型"}', 1, 100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_short_drama_inspiration` WHERE `tenant_id` = 0 AND `title` = '冬日河畔的静默');
