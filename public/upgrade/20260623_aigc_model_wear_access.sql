DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_model_wear';

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_model_wear','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant`
WHERE EXISTS (
  SELECT 1 FROM `la_app`
  WHERE `code`='aigc_model_wear' AND `status`='installed'
)
UNION ALL
SELECT 0,'aigc_model_wear','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE EXISTS (
  SELECT 1 FROM `la_app`
  WHERE `code`='aigc_model_wear' AND `status`='installed'
)
ON DUPLICATE KEY UPDATE
  `version`=VALUES(`version`),
  `buy_status`=VALUES(`buy_status`),
  `shelf_status`=VALUES(`shelf_status`),
  `enable_status`=VALUES(`enable_status`),
  `expire_time`=VALUES(`expire_time`),
  `update_time`=VALUES(`update_time`);
