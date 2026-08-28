-- Mark public and seeded short-drama styles as protected defaults.
SET @short_drama_style_table_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_short_drama_style'
);

SET @short_drama_style_default_column_sql := IF(
  @short_drama_style_table_exists = 1
    AND (SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'la_aigc_short_drama_style'
           AND COLUMN_NAME = 'is_default') = 0,
  'ALTER TABLE `la_aigc_short_drama_style` ADD COLUMN `is_default` tinyint NOT NULL DEFAULT 0 COMMENT ''是否默认画风'' AFTER `is_new`',
  'SELECT 1'
);
PREPARE short_drama_style_default_column_stmt FROM @short_drama_style_default_column_sql;
EXECUTE short_drama_style_default_column_stmt;
DEALLOCATE PREPARE short_drama_style_default_column_stmt;

SET @short_drama_style_default_backfill_sql := IF(
  @short_drama_style_table_exists = 1,
  'UPDATE `la_aigc_short_drama_style` SET `is_default` = 1 WHERE `tenant_id` = 0 AND `delete_time` = 0',
  'SELECT 1'
);
PREPARE short_drama_style_default_backfill_stmt FROM @short_drama_style_default_backfill_sql;
EXECUTE short_drama_style_default_backfill_stmt;
DEALLOCATE PREPARE short_drama_style_default_backfill_stmt;

-- Tenant defaults were copied from the public library before this marker existed.
-- Match the complete seeded payload so custom styles remain deletable.
SET @short_drama_style_tenant_backfill_sql := IF(
  @short_drama_style_table_exists = 1,
  'UPDATE `la_aigc_short_drama_style` target
   JOIN `la_aigc_short_drama_style` public_style
     ON public_style.`tenant_id` = 0
    AND public_style.`delete_time` = 0
    AND public_style.`name` = target.`name`
    AND public_style.`image` = target.`image`
    AND public_style.`description` = target.`description`
    AND public_style.`is_new` = target.`is_new`
    AND public_style.`sort` = target.`sort`
   SET target.`is_default` = 1
   WHERE target.`tenant_id` > 0
     AND target.`delete_time` = 0
     AND target.`is_default` = 0',
  'SELECT 1'
);
PREPARE short_drama_style_tenant_backfill_stmt FROM @short_drama_style_tenant_backfill_sql;
EXECUTE short_drama_style_tenant_backfill_stmt;
DEALLOCATE PREPARE short_drama_style_tenant_backfill_stmt;
