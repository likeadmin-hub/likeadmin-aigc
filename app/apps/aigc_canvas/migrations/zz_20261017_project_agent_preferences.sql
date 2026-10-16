-- Freeze the former user-wide canvas preferences separately for each existing project.
-- The unique owner key makes app/system upgrades and retries idempotent.
INSERT IGNORE INTO `la_aigc_llm_agent_preference` (`tenant_id`,`user_id`,`workspace_key`,`preferences`,`create_time`,`update_time`)
SELECT p.tenant_id,p.user_id,CONCAT('canvas:',p.id),
COALESCE((SELECT legacy.preferences FROM `la_aigc_llm_agent_preference` legacy WHERE legacy.tenant_id=p.tenant_id AND legacy.user_id=p.user_id AND legacy.workspace_key='aigc_canvas' LIMIT 1),'{"mode":"ask","models":{}}'),
UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_aigc_canvas_project` p WHERE p.delete_time=0 AND p.tenant_id>0 AND p.user_id>0;
