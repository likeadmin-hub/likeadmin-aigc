-- Explicit node deletion must not be undone by interrupted-task recovery.
SET @removed_nodes_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas' AND COLUMN_NAME = 'removed_node_ids_json') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas` ADD COLUMN `removed_node_ids_json` mediumtext NULL', 'SELECT 1');
PREPARE removed_nodes_stmt FROM @removed_nodes_sql;
EXECUTE removed_nodes_stmt;
DEALLOCATE PREPARE removed_nodes_stmt;
