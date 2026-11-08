-- Reviewed lossless widening; SqlMigrationExecutor preserves original defaults, nullability and comments.
ALTER TABLE `la_aigc_digital_human_billing` MODIFY COLUMN `quantity` DECIMAL(16,6) UNSIGNED;
