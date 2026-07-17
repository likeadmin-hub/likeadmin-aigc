SET @db_name = DATABASE();
SET @now = UNIX_TIMESTAMP();

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `thread_id` int unsigned NOT NULL DEFAULT 0,
  `request_id` varchar(96) NOT NULL DEFAULT '',
  `agent_code` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'running',
  `input_json` longtext,
  `output_json` longtext,
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`request_id`,`delete_time`),
  KEY `idx_thread` (`tenant_id`,`thread_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent runs';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_step` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `run_id` int unsigned NOT NULL DEFAULT 0,
  `agent_code` varchar(64) NOT NULL DEFAULT '',
  `step_type` varchar(64) NOT NULL DEFAULT '',
  `input_json` longtext,
  `output_json` longtext,
  `status` varchar(32) NOT NULL DEFAULT 'success',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_run` (`tenant_id`,`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent steps';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_memory` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `memory_type` varchar(64) NOT NULL DEFAULT 'project',
  `memory_key` varchar(128) NOT NULL DEFAULT '',
  `memory_json` longtext,
  `source_json` longtext,
  `summary` text,
  `version` int unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_memory` (`tenant_id`,`project_id`,`memory_type`,`memory_key`,`delete_time`),
  KEY `idx_project` (`tenant_id`,`project_id`,`memory_type`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent memory';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_tool_schema` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `tool_code` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(128) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `schema_json` longtext,
  `status` tinyint NOT NULL DEFAULT 1,
  `version` int NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tool` (`tenant_id`,`tool_code`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent tool schemas';

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND COLUMN_NAME='request_id')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD COLUMN `request_id` varchar(96) NOT NULL DEFAULT '''' AFTER `thread_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `la_aigc_canvas_agent_run`
SET `request_id` = CONCAT('legacy_', `id`)
WHERE `request_id` = '';

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_memory' AND COLUMN_NAME='source_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_memory` ADD COLUMN `source_json` longtext NULL AFTER `memory_json`, ADD COLUMN `version` int unsigned NOT NULL DEFAULT 1 AFTER `summary`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND INDEX_NAME='uk_request')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`request_id`,`delete_time`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call' AND COLUMN_NAME='idempotency_key')=0,
  'ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD COLUMN `idempotency_key` varchar(128) NOT NULL DEFAULT '''' AFTER `tool_code`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `la_aigc_canvas_agent_tool_call`
SET `idempotency_key` = SHA2(CONCAT('legacy|', `tenant_id`, '|', `user_id`, '|', `id`), 256)
WHERE `idempotency_key` = '';

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call' AND COLUMN_NAME='provider_task_id')=0,
  'ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD COLUMN `provider_task_id` varchar(128) NOT NULL DEFAULT '''' AFTER `output_json`, ADD COLUMN `retry_count` int unsigned NOT NULL DEFAULT 0 AFTER `provider_task_id`, ADD COLUMN `error_code` varchar(80) NOT NULL DEFAULT '''' AFTER `retry_count`, ADD COLUMN `started_at` int unsigned NOT NULL DEFAULT 0 AFTER `error`, ADD COLUMN `finished_at` int unsigned NOT NULL DEFAULT 0 AFTER `started_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_tool_call' AND INDEX_NAME='uk_idempotency')=0,
  'ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD UNIQUE KEY `uk_idempotency` (`tenant_id`,`user_id`,`idempotency_key`,`delete_time`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='agent_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `agent_policy_json` text NULL AFTER `output_policy_json`, ADD COLUMN `tool_schema_json` text NULL AFTER `agent_policy_json`, ADD COLUMN `canvas_output_policy_json` text NULL AFTER `tool_schema_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `la_aigc_canvas_skill`
SET `delete_time` = @now + `id`, `update_time` = @now
WHERE `delete_time` = 0
  AND NOT (
    `skill_key` = 'ecommerce_detail_page'
    AND `source_type` = 'builtin'
  );

INSERT INTO `la_aigc_canvas_skill` (
  `tenant_id`,`user_id`,`skill_key`,`name`,`description`,`category`,`skill_type`,`source_type`,
  `content_markdown`,`trigger_description`,`workflow_json`,`examples_json`,`negative_examples_json`,
  `required_slots_json`,`optional_slots_json`,`defaults_json`,`clarification_policy_json`,`tool_policy_json`,
  `output_policy_json`,`agent_policy_json`,`tool_schema_json`,`canvas_output_policy_json`,`cover_url`,
  `status`,`version`,`sort`,`create_time`,`update_time`,`delete_time`
)
SELECT DISTINCT
  ta.`tenant_id`,0,'ecommerce_detail_page','电商详情页设计',
  '根据商品资料和核心卖点规划完整电商详情页，生成分区视觉并组装到无限画布。','ecommerce','agent_workflow','builtin',
  '# 电商详情页设计\n先确认商品来源和核心卖点，信息完整后生成分区视觉并以 JSON Canvas 纵向组装。',
  '淘宝详情页、天猫详情页、商品详情长图、电商详情页、整套商品详情视觉。','{}',
  '["给这款蓝牙耳机做完整淘宝详情页","根据上传商品图生成5个详情页区块"]',
  '["写一段商品介绍文案","生成一张普通商品主图"]',
  '[{"key":"product_info","any_of_context":["uploaded_references"],"label":"商品信息或商品参考图"},{"key":"selling_points","label":"核心卖点"}]',
  '["platform","target_audience","style","brand_colors","section_count","ratio","detail_sections"]',
  '{"platform":"taobao","section_count":5,"style":"高级、简洁、真实商业摄影"}',
  '{"max_questions":3,"questions":{"product_info":"请告诉我商品是什么，或上传一张清晰商品图。","selling_points":"请补充至少一个核心卖点。"}}',
  '{"allowed_tools":["create_page","add_element","update_element","generate_image"]}',
  '{"format":"json_canvas","workspace_action":"apply_json_canvas"}',
  '{"agents":["master","planner","copy","visual","canvas"],"max_rounds":6,"max_tool_calls":24}',
  '{"required_tools":["create_page","add_element","generate_image"]}',
  '{"version":"1.1","page_width":750,"auto_apply":true,"layout":"vertical"}',
  '',1,1,1000,@now,@now,0
FROM `la_tenant_app` ta
WHERE ta.`app_code`='aigc_canvas'
  AND NOT EXISTS (
    SELECT 1 FROM `la_aigc_canvas_skill` s
    WHERE s.`tenant_id`=ta.`tenant_id` AND s.`skill_key`='ecommerce_detail_page' AND s.`delete_time`=0
  );
