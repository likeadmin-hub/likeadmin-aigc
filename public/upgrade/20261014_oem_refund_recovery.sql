-- OEM transaction refund recovery permission, additive and idempotent.
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.id,'A','退款交易核对','',0,'brand.order/refunds','','','','',0,0,0,'','core','core_oem_platform_refunds',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p WHERE p.source_menu_key='core_oem_platform_order' AND NOT EXISTS(SELECT 1 FROM `la_system_menu` e WHERE e.source_menu_key='core_oem_platform_refunds');
