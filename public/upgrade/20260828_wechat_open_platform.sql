-- 微信开放平台接入：幂等结构迁移，不修改既有直连配置
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `la_wechat_open_platform` (
  `id` int unsigned NOT NULL,
  `app_id` varchar(64) NOT NULL DEFAULT '', `app_secret` text, `token` text, `encoding_aes_key` text,
  `callback_url` varchar(255) NOT NULL DEFAULT '', `developer_app_id` varchar(64) NOT NULL DEFAULT '', `developer_secret` text, `upload_private_key` text,
  `upload_certificate` text, `upload_private_pem` text, `component_verify_ticket` text,
  `ticket_expire_time` int unsigned NOT NULL DEFAULT 0, `component_access_token` text,
  `component_token_expire_time` int unsigned NOT NULL DEFAULT 0, `status` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信开放平台配置';
CREATE TABLE IF NOT EXISTS `la_wechat_authorizers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `authorizer_appid` varchar(64) NOT NULL, `authorizer_type` varchar(20) NOT NULL DEFAULT '',
  `authorizer_name` varchar(120) NOT NULL DEFAULT '', `principal_name` varchar(160) NOT NULL DEFAULT '', `head_img` varchar(255) NOT NULL DEFAULT '',
  `func_info` longtext,
  `authorizer_refresh_token_ciphertext` text, `access_token_ciphertext` text, `access_token_expire_time` int unsigned NOT NULL DEFAULT 0,
  `authorization_status` tinyint unsigned NOT NULL DEFAULT 1, `unbind_time` int unsigned NOT NULL DEFAULT 0,
  `last_sync_time` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_type_app` (`tenant_id`,`authorizer_type`,`authorizer_appid`), KEY `idx_appid` (`authorizer_appid`), KEY `idx_tenant_status` (`tenant_id`,`authorization_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信授权账号';
CREATE TABLE IF NOT EXISTS `la_wechat_credentials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0, `authorizer_id` varchar(64) NOT NULL DEFAULT '',
  `app_secret` text, `token` text, `encoding_aes_key` text, `api_key` text, `upload_private_key` text, `upload_certificate` text, `upload_private_pem` text,
  `verify_status` tinyint unsigned NOT NULL DEFAULT 0, `last_verify_time` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信租户凭据';
CREATE TABLE IF NOT EXISTS `la_wechat_artifacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `version` varchar(40) NOT NULL, `artifact_dir` varchar(180) NOT NULL, `source_sha` varchar(64) NOT NULL DEFAULT '', `file_count` int unsigned NOT NULL DEFAULT 0, `sha256_manifest` longtext, `built_at` int unsigned NOT NULL DEFAULT 0, `verify_status` tinyint unsigned NOT NULL DEFAULT 0, `promoted` tinyint unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信小程序产物';
CREATE TABLE IF NOT EXISTS `la_wechat_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `template_id` varchar(64) NOT NULL DEFAULT '', `draft_id` bigint unsigned NOT NULL DEFAULT 0, `template_version` varchar(40) NOT NULL DEFAULT '', `template_desc` varchar(255) NOT NULL DEFAULT '', `artifact_id` int unsigned NOT NULL DEFAULT 0, `upload_status` varchar(20) NOT NULL DEFAULT 'pending', `error_message` text, `upload_time` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_artifact` (`artifact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信代码模板';
CREATE TABLE IF NOT EXISTS `la_wechat_mnp_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0, `authorizer_id` int unsigned NOT NULL DEFAULT 0, `template_id` int unsigned NOT NULL DEFAULT 0, `version` varchar(40) NOT NULL, `description` varchar(255) NOT NULL DEFAULT '', `ext_json` longtext, `experience_status` varchar(20) NOT NULL DEFAULT 'pending', `audit_status` varchar(20) NOT NULL DEFAULT 'none', `release_status` varchar(20) NOT NULL DEFAULT 'none', `rollback_from_id` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant` (`tenant_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信小程序版本';
CREATE TABLE IF NOT EXISTS `la_wechat_mnp_reviews` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `version_id` int unsigned NOT NULL DEFAULT 0, `audit_no` varchar(80) NOT NULL DEFAULT '', `audit_status` varchar(20) NOT NULL DEFAULT '', `reason` text, `detail` text, `response_summary` text, `submit_time` int unsigned NOT NULL DEFAULT 0, `finish_time` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_version` (`version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信小程序审核记录';
CREATE TABLE IF NOT EXISTS `la_wechat_callback_logs` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `request_id` varchar(64) NOT NULL DEFAULT '', `tenant_id` int unsigned NOT NULL DEFAULT 0, `event_type` varchar(40) NOT NULL DEFAULT '', `signature_valid` tinyint unsigned NOT NULL DEFAULT 0, `result` varchar(20) NOT NULL DEFAULT '', `error_message` varchar(255) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `idx_request` (`request_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信回调日志';
CREATE TABLE IF NOT EXISTS `la_wechat_api_logs` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `request_id` varchar(64) NOT NULL DEFAULT '', `tenant_id` int unsigned NOT NULL DEFAULT 0, `authorizer_id` int unsigned NOT NULL DEFAULT 0, `api_name` varchar(120) NOT NULL DEFAULT '', `wechat_code` int NOT NULL DEFAULT 0, `elapsed_ms` int unsigned NOT NULL DEFAULT 0, `retry_count` tinyint unsigned NOT NULL DEFAULT 0, `result` varchar(20) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `idx_api_time` (`api_name`,`create_time`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信接口日志';

SET @db_name = DATABASE();
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_open_platform' AND COLUMN_NAME='developer_app_id')=0,
  'ALTER TABLE `la_wechat_open_platform` ADD COLUMN `developer_app_id` varchar(64) NOT NULL DEFAULT '''' AFTER `callback_url`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_authorizers' AND COLUMN_NAME='func_info')=0,
  'ALTER TABLE `la_wechat_authorizers` ADD COLUMN `func_info` longtext NULL AFTER `head_img`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_wechat_templates' AND COLUMN_NAME='draft_id')=0,
  'ALTER TABLE `la_wechat_templates` ADD COLUMN `draft_id` bigint unsigned NOT NULL DEFAULT 0 AFTER `template_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 菜单种子：通过 source_menu_key 幂等写入，保留既有直连微信菜单。
SET @now := UNIX_TIMESTAMP();
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,'M','渠道管理','el-icon-Connection',500,'','channel','','','',0,1,0,'','core','core_channel_manage',1,@now,@now
WHERE NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_channel_manage');
SET @channel_manage_id := (SELECT `id` FROM `la_system_menu` WHERE (`source_menu_key`='core_channel_manage' OR (`name`='渠道管理' AND `pid`=0)) ORDER BY `id` LIMIT 1);
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @channel_manage_id,'M','开放平台','local-icon-weixin',10,'','open_platform','', '', '',0,1,0,'','core','core_open_platform',1,@now,@now
WHERE @channel_manage_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform');
SET @open_platform_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_open_platform' LIMIT 1);
UPDATE `la_system_menu` SET `pid`=@channel_manage_id, `type`='M', `name`='开放平台', `perms`='', `paths`='open_platform', `component`='', `is_show`=1, `is_disable`=0, `update_time`=@now WHERE @channel_manage_id IS NOT NULL AND `source_menu_key`='core_open_platform';
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @open_platform_id,'C',x.name,'',x.sort,x.perms,x.path,CASE x.key
  WHEN 'core_open_platform_config' THEN 'channel/open_platform/config'
  WHEN 'core_open_platform_callback' THEN 'channel/open_platform/callback'
  WHEN 'core_open_platform_authorizers' THEN 'channel/open_platform/authorizers'
  WHEN 'core_open_platform_official' THEN 'channel/open_platform/official'
  WHEN 'core_open_platform_miniprogram' THEN 'channel/open_platform/miniprogram'
  WHEN 'core_open_platform_versions' THEN 'channel/open_platform/versions'
  WHEN 'core_open_platform_authorizations' THEN 'channel/open_platform/authorizations'
  WHEN 'core_open_platform_logs' THEN 'channel/open_platform/logs'
  ELSE 'channel/open_platform/index' END,'', '',0,1,0,'','core',x.key,1,@now,@now
FROM (SELECT '平台配置' name,10 sort,'open_platform/config' perms,'open_platform/config' path,'core_open_platform_config' `key` UNION ALL SELECT '回调安全',20,'open_platform/callback','open_platform/callback','core_open_platform_callback' UNION ALL SELECT '授权账号',30,'open_platform/authorizers','open_platform/authorizers','core_open_platform_authorizers' UNION ALL SELECT '公号管理',40,'open_platform/official','open_platform/official','core_open_platform_official' UNION ALL SELECT '版本管理',50,'open_platform/miniprogram','open_platform/miniprogram','core_open_platform_miniprogram' UNION ALL SELECT '版本管理',60,'open_platform/versions','open_platform/versions','core_open_platform_versions' UNION ALL SELECT '授权记录',70,'open_platform/authorizations','open_platform/authorizations','core_open_platform_authorizations' UNION ALL SELECT '调用日志',80,'open_platform/logs','open_platform/logs','core_open_platform_logs') x
WHERE NOT EXISTS (SELECT 1 FROM `la_system_menu` m WHERE m.`source_menu_key`=x.`key`);
UPDATE `la_system_menu` SET `component`=CASE `source_menu_key`
  WHEN 'core_open_platform_config' THEN 'channel/open_platform/config'
  WHEN 'core_open_platform_callback' THEN 'channel/open_platform/callback'
  WHEN 'core_open_platform_authorizers' THEN 'channel/open_platform/authorizers'
  WHEN 'core_open_platform_official' THEN 'channel/open_platform/official'
  WHEN 'core_open_platform_miniprogram' THEN 'channel/open_platform/miniprogram'
  WHEN 'core_open_platform_versions' THEN 'channel/open_platform/versions'
  WHEN 'core_open_platform_authorizations' THEN 'channel/open_platform/authorizations'
  WHEN 'core_open_platform_logs' THEN 'channel/open_platform/logs'
  ELSE `component` END, `update_time`=@now
WHERE `source_menu_key` IN ('core_open_platform_config','core_open_platform_callback','core_open_platform_authorizers','core_open_platform_official','core_open_platform_miniprogram','core_open_platform_versions','core_open_platform_authorizations','core_open_platform_logs');

SET @tenant_channel_id := (SELECT `id` FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND (`source_menu_key`='core_tenant_channel_manage' OR `name`='渠道设置') AND `pid`=0 ORDER BY `id` LIMIT 1);
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@tenant_channel_id,'M','微信授权','local-icon-weixin',70,'','wechat_auth','','','',0,1,0,'','core','core_tenant_open_platform',1,@now,@now
WHERE @tenant_channel_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='core_tenant_open_platform');
SET @tenant_open_platform_id := (SELECT `id` FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='core_tenant_open_platform' ORDER BY `id` DESC LIMIT 1);
UPDATE `la_tenant_system_menu` SET `pid`=@tenant_channel_id, `type`='M', `name`='微信授权', `perms`='', `paths`='wechat_auth', `component`='', `is_show`=1, `is_disable`=0, `update_time`=@now WHERE @tenant_channel_id IS NOT NULL AND `tenant_id`=0 AND `source_menu_key`='core_tenant_open_platform';
UPDATE `la_tenant_system_menu` SET `name`='微信配置', `update_time`=@now WHERE `tenant_id`=0 AND `perms`='channel.open_setting/getConfig' AND `name`='微信开放平台';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@tenant_open_platform_id,'C',x.name,'',x.sort,x.perms,x.path,'channel/open_platform/index','','',0,1,0,'','core',x.key,1,@now,@now
FROM (SELECT '授权绑定' name,10 sort,'channel.open_platform/authUrl' perms,'open_platform/bind' path,'core_tenant_open_platform_bind' `key` UNION ALL SELECT '账号管理',20,'channel.open_platform/accounts','open_platform/accounts','core_tenant_open_platform_accounts') x
WHERE @tenant_open_platform_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` m WHERE m.`tenant_id`=0 AND m.`source_menu_key`=x.`key`);
UPDATE `la_tenant_system_menu` SET `pid`=@tenant_open_platform_id, `update_time`=@now
WHERE @tenant_open_platform_id IS NOT NULL AND `tenant_id`=0 AND `source_menu_key` IN ('core_tenant_open_platform_bind','core_tenant_open_platform_accounts');
UPDATE `la_tenant_system_menu` SET `is_show`=0, `is_disable`=1, `update_time`=@now
WHERE `tenant_id`=0 AND (`source_menu_key` IN ('core_tenant_open_platform_official','core_tenant_open_platform_mini','core_tenant_open_platform_authorizations') OR `source_menu_key` LIKE 'core_tenant_open_%');
