SET @image_human_billing_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_image_human_billing'
);

SET @image_human_billing_sql = IF(
  @image_human_billing_exists > 0,
  'UPDATE `la_image_human_billing` b
JOIN (
  SELECT `tenant_id`, `task_id`, MIN(`id`) AS keep_id
  FROM `la_image_human_billing`
  WHERE `task_id` > 0
  GROUP BY `tenant_id`, `task_id`
  HAVING COUNT(*) > 1
) d ON d.`tenant_id` = b.`tenant_id`
  AND d.`task_id` = b.`task_id`
  AND b.`id` <> d.`keep_id`
SET b.`billing_status` = ''duplicate_ignored'',
    b.`update_time` = UNIX_TIMESTAMP()
WHERE b.`billing_status` = ''deducted''',
  'SELECT 1'
);
PREPARE stmt FROM @image_human_billing_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
