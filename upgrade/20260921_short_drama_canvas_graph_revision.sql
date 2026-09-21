-- Existing-install path. No task, asset or business JSON rewrite.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(40) NOT NULL DEFAULT '无标题空间',
  `nodes_json` longtext,
  `edges_json` longtext,
  `viewport_json` text,
  `removed_node_ids_json` mediumtext,
  `graph_revision` int unsigned NOT NULL DEFAULT 0,
  `schema_version` int unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧画布';
SET @canvas_graph_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas' AND COLUMN_NAME = 'graph_revision') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas` ADD COLUMN `graph_revision` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE canvas_graph_stmt FROM @canvas_graph_sql;
EXECUTE canvas_graph_stmt;
DEALLOCATE PREPARE canvas_graph_stmt;
SET @canvas_schema_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas' AND COLUMN_NAME = 'schema_version') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas` ADD COLUMN `schema_version` int unsigned NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE canvas_schema_stmt FROM @canvas_schema_sql;
EXECUTE canvas_schema_stmt;
DEALLOCATE PREPARE canvas_schema_stmt;
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_mutation_receipt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `base_revision` int unsigned NOT NULL,
  `result_revision` int unsigned NOT NULL,
  `result_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_key` (`tenant_id`,`user_id`,`canvas_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧画布图操作回执';
SET @canvas_key_sql = IF((SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_mutation_receipt' AND COLUMN_NAME = 'request_key') <> 'ascii_bin', 'ALTER TABLE `la_aigc_short_drama_canvas_mutation_receipt` MODIFY COLUMN `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, MODIFY COLUMN `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'SELECT 1');
PREPARE canvas_key_stmt FROM @canvas_key_sql;
EXECUTE canvas_key_stmt;
DEALLOCATE PREPARE canvas_key_stmt;
