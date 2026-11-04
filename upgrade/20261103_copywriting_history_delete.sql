-- Soft-delete finished works; SqlMigrationExecutor makes repeated ADD COLUMN a no-op.
ALTER TABLE `la_aigc_copywriting_task` ADD COLUMN `delete_time` int NOT NULL DEFAULT 0;
