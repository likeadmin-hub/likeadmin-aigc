-- 微信开放平台草稿上传记录：平台构建产物上传至开发小程序草稿箱的审计留痕。
CREATE TABLE IF NOT EXISTS `la_wechat_template_drafts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `artifact_id` int unsigned NOT NULL DEFAULT 0,
  `draft_id` bigint unsigned NOT NULL DEFAULT 0,
  `developer_app_id` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(64) NOT NULL DEFAULT '',
  `description` varchar(255) NOT NULL DEFAULT '',
  `source_sha` varchar(64) NOT NULL DEFAULT '',
  `upload_status` varchar(20) NOT NULL DEFAULT 'pending',
  `output` text,
  `error_message` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_artifact_time` (`artifact_id`,`create_time`),
  KEY `idx_draft_id` (`draft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信开放平台草稿上传记录';
