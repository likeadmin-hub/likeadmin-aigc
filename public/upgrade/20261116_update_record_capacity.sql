-- Lossless updater metadata capacity repair. The executor preserves live column semantics.
ALTER TABLE `la_update_task` MODIFY COLUMN `preflight_json` MEDIUMTEXT;
ALTER TABLE `la_update_task` MODIFY COLUMN `result_json` MEDIUMTEXT;
ALTER TABLE `la_update_package` MODIFY COLUMN `manifest_json` MEDIUMTEXT;
