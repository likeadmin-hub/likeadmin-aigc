-- Global platform license cache. No tenant table or tenant-owned config changes.
CREATE TABLE IF NOT EXISTS `la_site_license_access_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `context_key` char(64) NOT NULL,
  `license_no` varchar(100) NOT NULL,
  `license_version` int unsigned NOT NULL DEFAULT 0,
  `domain` varchar(255) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `response_json` mediumtext NULL,
  `request_nonce` varchar(100) NOT NULL DEFAULT '',
  `issued_at` bigint NOT NULL DEFAULT 0,
  `trusted_issued_at` bigint NOT NULL DEFAULT 0,
  `expires_at` bigint NOT NULL DEFAULT 0,
  `checked_at` bigint NOT NULL DEFAULT 0,
  `next_retry_at` bigint NOT NULL DEFAULT 0,
  `failure_count` int unsigned NOT NULL DEFAULT 0,
  `generation` bigint unsigned NOT NULL DEFAULT 0,
  `lock_token` char(32) NOT NULL DEFAULT '',
  `lock_until` bigint NOT NULL DEFAULT 0,
  `error_code` varchar(100) NOT NULL DEFAULT '',
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `create_time` bigint NOT NULL DEFAULT 0,
  `update_time` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_context` (`context_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台站点签名授权缓存';
-- Some older installations omitted the scheduler table. Preserve any existing table.
CREATE TABLE IF NOT EXISTS `la_crontab`
(
    `id`          int(11)     NOT NULL AUTO_INCREMENT,
    `name`        varchar(32) NOT NULL COMMENT '定时任务名称',
    `type`        tinyint(1)  NOT NULL COMMENT '类型 1-定时任务',
    `system`      tinyint(4)           DEFAULT '0' COMMENT '是否系统任务 0-否 1-是',
    `remark`      varchar(255)         DEFAULT '' COMMENT '备注',
    `command`     varchar(64) NOT NULL COMMENT '命令内容',
    `params`      varchar(64)          DEFAULT '' COMMENT '参数',
    `status`      tinyint(1)  NOT NULL DEFAULT '0' COMMENT '状态 1-运行 2-停止 3-错误',
    `expression`  varchar(64) NOT NULL COMMENT '运行规则',
    `error`       varchar(256)         DEFAULT NULL COMMENT '运行失败原因',
    `last_time`   int(11)              DEFAULT NULL COMMENT '最后执行时间',
    `time`        varchar(64)          DEFAULT '0' COMMENT '实时执行时长',
    `max_time`    varchar(64)          DEFAULT '0' COMMENT '最大执行时长',
    `create_time` int(10)              DEFAULT NULL COMMENT '创建时间',
    `update_time` int(10)              DEFAULT NULL COMMENT '更新时间',
    `delete_time` int(10)              DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='计划任务表';
INSERT INTO `la_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT '站点授权同步',1,1,'刷新平台商业授权与版权编辑资格','site-license:refresh','',1,'* * * * *','',0,'0','0',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),NULL
WHERE NOT EXISTS (SELECT 1 FROM `la_crontab` WHERE `command`='site-license:refresh' AND (`delete_time` IS NULL OR `delete_time`=0));
