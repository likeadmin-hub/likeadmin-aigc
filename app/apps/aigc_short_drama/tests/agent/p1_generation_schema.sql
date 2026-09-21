-- Isolated intent lifecycle draft. Not registered or exposed by the live Canvas adapter yet.
CREATE TABLE IF NOT EXISTS la_aigc_short_drama_canvas_generation_intent (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, canvas_id INT UNSIGNED NOT NULL,
 request_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 canvas_run_id INT UNSIGNED NOT NULL, node_id VARCHAR(64) NOT NULL,
 snapshot_json LONGTEXT NOT NULL, state VARCHAR(32) NOT NULL,
 fencing_version INT UNSIGNED NOT NULL DEFAULT 0, claim_token CHAR(48) NOT NULL DEFAULT '',
 lease_until INT UNSIGNED NOT NULL DEFAULT 0, provider_task_id VARCHAR(100) NOT NULL DEFAULT '',
 error_code VARCHAR(64) NOT NULL DEFAULT '', create_time INT UNSIGNED NOT NULL, update_time INT UNSIGNED NOT NULL,
 UNIQUE KEY uk_scope_key(tenant_id,user_id,canvas_id,request_key),
 UNIQUE KEY uk_canvas_run(canvas_run_id), KEY idx_recovery(state,lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
