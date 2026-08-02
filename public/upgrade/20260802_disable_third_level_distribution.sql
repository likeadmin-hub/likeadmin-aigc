-- Disable the retired third-level distribution path while preserving historical commission records.
UPDATE `la_distribution_config`
SET `level_count` = LEAST(`level_count`, 2), `level3_rate` = 0.0000, `update_time` = UNIX_TIMESTAMP();

UPDATE `la_distribution_package_rule`
SET `level_count` = LEAST(`level_count`, 2), `level3_rate` = 0.0000, `update_time` = UNIX_TIMESTAMP();

UPDATE `la_distribution_relation`
SET `level3_user_id` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `level3_user_id` <> 0;

ALTER TABLE `la_distribution_config` ALTER COLUMN `level_count` SET DEFAULT 2;
ALTER TABLE `la_distribution_package_rule` ALTER COLUMN `level_count` SET DEFAULT 2;
