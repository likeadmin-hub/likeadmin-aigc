CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_thread` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Tenant ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'User ID',
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Canvas project ID',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT 'Thread title',
  `status` varchar(30) NOT NULL DEFAULT 'active' COMMENT 'active/archived',
  `summary` text COMMENT 'Context summary',
  `meta_json` longtext COMMENT 'Metadata',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_project` (`tenant_id`,`project_id`,`delete_time`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent threads';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_message` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Tenant ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'User ID',
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Canvas project ID',
  `thread_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Thread ID',
  `role` varchar(30) NOT NULL DEFAULT '' COMMENT 'user/assistant/system',
  `content` longtext COMMENT 'Message content',
  `content_json` longtext COMMENT 'Structured content',
  `status` varchar(30) NOT NULL DEFAULT 'success' COMMENT 'running/success/failed/canceled',
  `error` text COMMENT 'Error message',
  `meta_json` longtext COMMENT 'Metadata',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`tenant_id`,`thread_id`,`delete_time`),
  KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent messages';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_tool_call` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Tenant ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'User ID',
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Canvas project ID',
  `thread_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Thread ID',
  `message_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Message ID',
  `tool_code` varchar(80) NOT NULL DEFAULT '' COMMENT 'Tool code',
  `status` varchar(30) NOT NULL DEFAULT 'running' COMMENT 'running/success/failed/canceled',
  `input_json` longtext COMMENT 'Tool input',
  `output_json` longtext COMMENT 'Tool output',
  `error` text COMMENT 'Error message',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_message` (`tenant_id`,`message_id`,`delete_time`),
  KEY `idx_tool` (`tenant_id`,`tool_code`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent tool calls';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_workspace_action` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Tenant ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'User ID',
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Canvas project ID',
  `thread_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Thread ID',
  `message_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Message ID',
  `tool_call_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'Tool call ID',
  `action_type` varchar(60) NOT NULL DEFAULT '' COMMENT 'Workspace action type',
  `status` varchar(30) NOT NULL DEFAULT 'pending' COMMENT 'pending/applied/rejected/failed',
  `input_json` longtext COMMENT 'Action input',
  `result_json` longtext COMMENT 'Action result',
  `error` text COMMENT 'Error message',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_thread` (`tenant_id`,`thread_id`,`delete_time`),
  KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`),
  KEY `idx_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent workspace actions';
