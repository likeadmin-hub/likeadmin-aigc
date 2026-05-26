-- Token billing and default OpenAI-compatible Qwen channel for AIGC LLM.

SET @aigc_llm_table = 'la_aigc_llm_model';
SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_input_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台输入成本，点/百万Token'' AFTER `tenant_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_input_unit_cost');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_output_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台输出成本，点/百万Token'' AFTER `platform_input_unit_cost`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_output_unit_cost');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `tenant_input_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''用户输入售价，点/百万Token'' AFTER `platform_output_unit_cost`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'tenant_input_unit_price');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `tenant_output_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''用户输出售价，点/百万Token'' AFTER `tenant_input_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'tenant_output_unit_price');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `billing_unit` varchar(30) NOT NULL DEFAULT ''tokens_1m'' COMMENT ''计费单位'' AFTER `tenant_output_unit_price`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'billing_unit');
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

CREATE TABLE IF NOT EXISTS `la_aigc_llm_usage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `session_id` int unsigned NOT NULL DEFAULT 0,
  `message_id` int unsigned NOT NULL DEFAULT 0,
  `channel_code` varchar(64) NOT NULL DEFAULT '',
  `model_code` varchar(64) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_model` varchar(100) NOT NULL DEFAULT '',
  `provider_request_id` varchar(120) NOT NULL DEFAULT '',
  `prompt_tokens` int unsigned NOT NULL DEFAULT 0,
  `completion_tokens` int unsigned NOT NULL DEFAULT 0,
  `total_tokens` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '租户成本扣点',
  `user_charge_points` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `price_json` text,
  `extra_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_message` (`tenant_id`,`message_id`),
  KEY `idx_session` (`tenant_id`,`session_id`),
  KEY `idx_user_time` (`tenant_id`,`user_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话Token用量扣费明细';

UPDATE `la_aigc_llm_model`
SET `platform_input_unit_cost` = CASE WHEN `platform_input_unit_cost` = 0 THEN `platform_unit_cost` ELSE `platform_input_unit_cost` END,
    `platform_output_unit_cost` = CASE WHEN `platform_output_unit_cost` = 0 THEN `platform_unit_cost` ELSE `platform_output_unit_cost` END,
    `tenant_input_unit_price` = CASE WHEN `tenant_input_unit_price` = 0 THEN `tenant_unit_price` ELSE `tenant_input_unit_price` END,
    `tenant_output_unit_price` = CASE WHEN `tenant_output_unit_price` = 0 THEN `tenant_unit_price` ELSE `tenant_output_unit_price` END,
    `billing_unit` = 'tokens_1m',
    `update_time` = UNIX_TIMESTAMP();

INSERT INTO `la_aigc_llm_channel`
(`tenant_id`, `code`, `name`, `provider`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
VALUES
(0, 'dashscope_compatible', 'Qwen3.6-Plus 兼容通道', 'openai_compatible', '{"base_url":"","stream_path":"/api/v1/chat/completions","api_key":"","timeout":120,"ssl_verify":0,"remark":"Qwen3.6-Plus OpenAI compatible"}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`name`=VALUES(`name`),
`provider`=VALUES(`provider`),
`config_json`=JSON_SET(
  VALUES(`config_json`),
  '$.api_key',
  COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`config_json`, '$.api_key')), ''), '')
),
`status`=VALUES(`status`),
`sort`=VALUES(`sort`),
`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_llm_model`
(`tenant_id`, `channel_code`, `code`, `name`, `provider`, `model`, `context_limit`, `platform_unit_cost`, `tenant_unit_price`, `platform_input_unit_cost`, `platform_output_unit_cost`, `tenant_input_unit_price`, `tenant_output_unit_price`, `billing_unit`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
VALUES
(0, 'dashscope_compatible', 'qwen3_6_plus', 'Qwen3.6-Plus', 'openai_compatible', 'qwen3.6-plus', 24, 200.00, 200.00, 200.0000, 1200.0000, 200.0000, 1200.0000, 'tokens_1m', '{"temperature":0.7,"max_tokens":8192,"enable_thinking":false,"stream_options":{"include_usage":true}}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `channel_code`=VALUES(`channel_code`), `name`=VALUES(`name`), `provider`=VALUES(`provider`), `model`=VALUES(`model`), `context_limit`=VALUES(`context_limit`), `platform_unit_cost`=VALUES(`platform_unit_cost`), `tenant_unit_price`=VALUES(`tenant_unit_price`), `platform_input_unit_cost`=VALUES(`platform_input_unit_cost`), `platform_output_unit_cost`=VALUES(`platform_output_unit_cost`), `tenant_input_unit_price`=VALUES(`tenant_input_unit_price`), `tenant_output_unit_price`=VALUES(`tenant_output_unit_price`), `billing_unit`=VALUES(`billing_unit`), `config_json`=VALUES(`config_json`), `status`=VALUES(`status`), `sort`=VALUES(`sort`), `update_time`=VALUES(`update_time`);

UPDATE `la_aigc_llm_config`
SET `provider_mode` = 'platform',
    `provider` = 'openai_compatible',
    `model` = 'qwen3_6_plus',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0;

UPDATE `la_aigc_llm_config`
SET `provider` = 'openai_compatible',
    `model` = 'qwen3_6_plus',
    `update_time` = UNIX_TIMESTAMP()
WHERE `provider` = 'mock' OR `model` IN ('mock_chat_basic', 'mock_chat_fast');

DELETE FROM `la_aigc_llm_model`
WHERE `provider` = 'mock' OR `code` IN ('mock_chat_basic', 'mock_chat_fast');

DELETE FROM `la_aigc_llm_channel`
WHERE `provider` = 'mock' OR `code` = 'mock_llm';
