-- Test-only draft schema; not a production migration or registered capability.
ALTER TABLE la_aigc_short_drama_canvas ADD COLUMN graph_revision INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN schema_version INT UNSIGNED NOT NULL DEFAULT 1;
CREATE TABLE la_aigc_short_drama_canvas_mutation_receipt (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, canvas_id INT UNSIGNED NOT NULL,
 request_key VARCHAR(100) NOT NULL, request_hash CHAR(64) NOT NULL,
 base_revision INT UNSIGNED NOT NULL, result_revision INT UNSIGNED NOT NULL,
 result_json LONGTEXT NOT NULL, create_time INT UNSIGNED NOT NULL,
 UNIQUE KEY uk_scope_key(tenant_id,user_id,canvas_id,request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
