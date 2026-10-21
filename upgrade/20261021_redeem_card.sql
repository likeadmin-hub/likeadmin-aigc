-- Single-use tenant-issued cards. Shared tables are always tenant_id scoped.
CREATE TABLE IF NOT EXISTS `la_redeem_card_batch` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `batch_no` varchar(40) NOT NULL, `name` varchar(60) NOT NULL, `type` varchar(16) NOT NULL,
 `quantity` smallint unsigned NOT NULL, `points` decimal(12,2) NOT NULL DEFAULT 0,
 `plan_id` int unsigned NOT NULL DEFAULT 0, `plan_name` varchar(100) NOT NULL DEFAULT '',
 `duration_months` smallint unsigned NOT NULL DEFAULT 0, `reward_snapshot` longtext NOT NULL,
 `expire_time` int unsigned NOT NULL DEFAULT 0, `status` varchar(16) NOT NULL DEFAULT 'enabled',
 `remark` varchar(255) NOT NULL DEFAULT '', `admin_id` int unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `batch_no` (`batch_no`), KEY `tenant_list` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_redeem_card` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `batch_id` bigint unsigned NOT NULL,
 `code_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `code_mask` varchar(40) NOT NULL, `status` varchar(16) NOT NULL DEFAULT 'unused',
 `redeemed_user_id` int unsigned NOT NULL DEFAULT 0, `redeem_time` int unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `code_hash` (`code_hash`), KEY `tenant_batch` (`tenant_id`,`batch_id`,`id`), KEY `tenant_status` (`tenant_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_redeem_card_record` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `card_id` bigint unsigned NOT NULL,
 `batch_id` bigint unsigned NOT NULL, `user_id` int unsigned NOT NULL, `code_mask` varchar(40) NOT NULL,
 `type` varchar(16) NOT NULL, `points` decimal(12,2) NOT NULL DEFAULT 0,
 `plan_id` int unsigned NOT NULL DEFAULT 0, `plan_name` varchar(100) NOT NULL DEFAULT '',
 `duration_months` smallint unsigned NOT NULL DEFAULT 0, `member_expire_time` int unsigned NOT NULL DEFAULT 0,
 `source_sn` varchar(100) NOT NULL, `redeem_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `single_card` (`card_id`), UNIQUE KEY `source_sn` (`source_sn`),
 KEY `tenant_user` (`tenant_id`,`user_id`,`id`), KEY `tenant_batch` (`tenant_id`,`batch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_redeem_card_attempt` (
 `subject_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `window_start` int unsigned NOT NULL,
 `attempts` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`subject_hash`), KEY `last_update` (`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Split tenant menus are also provisioned by the existing PackageProvisionService/MenuLogic path.
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time) SELECT p.tenant_id,p.id,'C','卡密管理','el-icon-Tickets',90,'finance.redeem_card/lists','redeem_card','finance/redeem_card','','',0,1,0,'','core','core_tenant_redeem_card',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.paths='finance' AND p.type='M' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='finance.redeem_card/lists');
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time) SELECT p.tenant_id,p.id,'A','生成卡密','',0,'finance.redeem_card/generate','','','','',0,0,0,'','core','core_tenant_redeem_card_generate',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='finance.redeem_card/lists' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='finance.redeem_card/generate');
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time) SELECT p.tenant_id,p.id,'A','批次列表','',0,'finance.redeem_card/batches','','','','',0,0,0,'','core','core_tenant_redeem_card_batches',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='finance.redeem_card/lists' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='finance.redeem_card/batches');
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time) SELECT p.tenant_id,p.id,'A','兑换记录','',0,'finance.redeem_card/records','','','','',0,0,0,'','core','core_tenant_redeem_card_records',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='finance.redeem_card/lists' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='finance.redeem_card/records');
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time) SELECT p.tenant_id,p.id,'A','停用卡密','',0,'finance.redeem_card/disable','','','','',0,0,0,'','core','core_tenant_redeem_card_disable',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='finance.redeem_card/lists' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='finance.redeem_card/disable');
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time) SELECT p.tenant_id,p.id,'A','权益选项','',0,'finance.redeem_card/options','','','','',0,0,0,'','core','core_tenant_redeem_card_options',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='finance.redeem_card/lists' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.perms='finance.redeem_card/options');
