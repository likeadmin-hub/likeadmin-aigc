-- Retire only the bundled system demo. Keep tenant uploads and source files.
UPDATE `la_aigc_short_drama_inspiration`
SET `status` = 0, `delete_time` = UNIX_TIMESTAMP(), `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `title` = '冬日河畔的静默' AND `delete_time` = 0;
