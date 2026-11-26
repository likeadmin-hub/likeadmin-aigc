CREATE TABLE IF NOT EXISTS `la_platform_admin_session` (
  `token` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `admin_id` int unsigned NOT NULL,
  `terminal` tinyint NOT NULL,
  `login_ip` varchar(45) CHARACTER SET ascii NOT NULL,
  `expire_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`token`),
  KEY `admin_session_expiry` (`admin_id`, `expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台管理员独立登录会话';
