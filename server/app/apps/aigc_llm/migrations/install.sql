-- AIGC LLM conversation application business tables.

CREATE TABLE IF NOT EXISTS `la_aigc_llm_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'openai_compatible',
  `model` varchar(100) NOT NULL DEFAULT 'qwen3_6_plus',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话配置';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT 'openai_compatible',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话通道';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_model` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `channel_code` varchar(64) NOT NULL DEFAULT '',
  `code` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(80) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT 'openai_compatible',
  `model` varchar(100) NOT NULL DEFAULT '',
  `context_limit` int unsigned NOT NULL DEFAULT 12,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_tenant_channel` (`tenant_id`,`channel_code`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话模型';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_word` (`tenant_id`,`word`),
  KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话敏感词';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_session` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(100) NOT NULL DEFAULT '',
  `model_code` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'idle',
  `last_message_at` int unsigned NOT NULL DEFAULT 0,
  `message_count` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_last_message` (`tenant_id`,`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话会话';

CREATE TABLE IF NOT EXISTS `la_aigc_llm_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `session_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  `content` mediumtext,
  `seq` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'done',
  `finish_reason` varchar(50) NOT NULL DEFAULT '',
  `token_usage_json` text,
  `parent_user_message_id` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_session_seq` (`tenant_id`,`session_id`,`seq`),
  KEY `idx_parent_user` (`tenant_id`,`parent_user_message_id`),
  KEY `idx_user` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC对话消息';

INSERT INTO `la_aigc_llm_config`
(`tenant_id`, `provider_mode`, `provider`, `model`, `config_json`, `status`, `create_time`, `update_time`)
SELECT 0, 'platform', 'openai_compatible', 'qwen3_6_plus', '{"system_prompt":"","max_context_messages":12,"auto_title_chars":18}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_llm_config` WHERE `tenant_id` = 0);

INSERT INTO `la_aigc_llm_channel`
(`tenant_id`, `code`, `name`, `provider`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'dashscope_compatible', 'Qwen3.6-Plus 兼容通道', 'openai_compatible', '{"base_url":"","stream_path":"/api/v1/chat/completions","api_key":"","timeout":120,"ssl_verify":0,"remark":"Qwen3.6-Plus OpenAI compatible"}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_llm_channel` WHERE `tenant_id` = 0 AND `code` = 'dashscope_compatible');

INSERT INTO `la_aigc_llm_model`
(`tenant_id`, `channel_code`, `code`, `name`, `provider`, `model`, `context_limit`, `platform_unit_cost`, `tenant_unit_price`, `config_json`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'dashscope_compatible', 'qwen3_6_plus', 'Qwen3.6-Plus', 'openai_compatible', 'qwen3.6-plus', 24, 200.00, 200.00, '{"temperature":0.7,"max_tokens":8192,"enable_thinking":false,"stream_options":{"include_usage":true}}', 1, 1000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_aigc_llm_model` WHERE `tenant_id` = 0 AND `code` = 'qwen3_6_plus');
