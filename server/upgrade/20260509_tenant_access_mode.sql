ALTER TABLE `la_tenant` ADD COLUMN `access_mode` varchar(20) NOT NULL DEFAULT 'subdomain' COMMENT '访问方式:subdomain自动子域名,id租户ID,alias别名' AFTER `domain_alias_enable`;
UPDATE `la_tenant` SET `access_mode` = 'alias' WHERE `domain_alias_enable` = 0 AND IFNULL(`domain_alias`, '') <> '';
