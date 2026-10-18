-- Isolate canvas conversations without changing historical home sessions.

SET @agent_scope_table = 'la_aigc_llm_session';
SET @agent_scope_sql = (SELECT IF(COUNT(*)=0, CONCAT('ALTER TABLE `', @agent_scope_table, '` ADD COLUMN `workspace_key` varchar(160) NOT NULL DEFAULT ''home'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@agent_scope_table AND COLUMN_NAME='workspace_key');
PREPARE agent_scope_stmt FROM @agent_scope_sql;
EXECUTE agent_scope_stmt;
DEALLOCATE PREPARE agent_scope_stmt;

SET @agent_scope_table = 'la_aigc_llm_agent_preference';
SET @agent_scope_sql = (SELECT IF(COUNT(*)=0, CONCAT('ALTER TABLE `', @agent_scope_table, '` ADD COLUMN `workspace_key` varchar(160) NOT NULL DEFAULT ''aigc_llm'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@agent_scope_table AND COLUMN_NAME='workspace_key');
PREPARE agent_scope_stmt FROM @agent_scope_sql;
EXECUTE agent_scope_stmt;
DEALLOCATE PREPARE agent_scope_stmt;

SET @agent_scope_table = 'la_aigc_llm_agent_preference';
SET @agent_scope_sql = (SELECT IF(COUNT(*)=2, CONCAT('ALTER TABLE `', @agent_scope_table, '` DROP INDEX `owner`, ADD UNIQUE KEY `owner` (`tenant_id`,`user_id`,`workspace_key`)'), 'SELECT 1') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@agent_scope_table AND INDEX_NAME='owner');
PREPARE agent_scope_stmt FROM @agent_scope_sql;
EXECUTE agent_scope_stmt;
DEALLOCATE PREPARE agent_scope_stmt;
