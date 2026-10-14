-- Runs after 20261013_oem_self_service_v2.sql. Repeat execution preserves explicit qualification changes.
ALTER TABLE `la_tenant_brand_event` ADD COLUMN `payload` longtext;

UPDATE `la_tenant` t SET t.`oem_sales_enabled`=1
WHERE NOT EXISTS(SELECT 1 FROM `la_config` WHERE `type`='oem_migration' AND `name`='legacy_qualification_v1') AND t.`oem_flow_enabled`=0 AND (
  EXISTS(SELECT 1 FROM `la_tenant_brand_package_price` p WHERE p.`tenant_id`=t.`id`)
  OR EXISTS(SELECT 1 FROM `la_tenant_brand_quota_order` q WHERE q.`tenant_id`=t.`id` AND q.`pay_status`=1)
);
INSERT INTO `la_config` (`type`,`name`,`value`) SELECT 'oem_migration','legacy_qualification_v1','done' WHERE NOT EXISTS(SELECT 1 FROM `la_config` WHERE `type`='oem_migration' AND `name`='legacy_qualification_v1');
