CREATE TABLE IF NOT EXISTS `la_aigc_video_enhance_config` (
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `settings` json NOT NULL,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='视频变清晰创作配置';
