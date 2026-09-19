SET @db_name := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_delivery_item' AND COLUMN_NAME='pending_action_json')=0,
  'ALTER TABLE `la_aigc_canvas_delivery_item` ADD COLUMN `pending_action_json` longtext AFTER `reference_assets_json`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
