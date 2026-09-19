-- Keep upstream cost, platform-to-tenant price, and tenant-to-user price separate.
SET @aigc_llm_table = 'la_aigc_llm_model';

SET @aigc_llm_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table)
        AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_input_unit_price'),
        CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_input_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000'),
        'SELECT 1'
    )
);
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table)
        AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_output_unit_price'),
        CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_output_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000'),
        'SELECT 1'
    )
);
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_input_cost = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_input_unit_cost'),
    'NULLIF(`platform_input_unit_cost`, 0)',
    IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_unit_cost'),
        'NULLIF(`platform_unit_cost`, 0)',
        '0'
    )
);
SET @aigc_llm_output_cost = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_output_unit_cost'),
    'NULLIF(`platform_output_unit_cost`, 0)',
    @aigc_llm_input_cost
);
SET @aigc_llm_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table),
        CONCAT(
            'UPDATE `', @aigc_llm_table, '` SET `platform_input_unit_price` = CASE ',
            'WHEN `platform_input_unit_price` = 0 THEN COALESCE(', @aigc_llm_input_cost, ', 0) ELSE `platform_input_unit_price` END, ',
            '`platform_output_unit_price` = CASE ',
            'WHEN `platform_output_unit_price` = 0 THEN COALESCE(', @aigc_llm_output_cost, ', 0) ELSE `platform_output_unit_price` END'
        ),
        'SELECT 1'
    )
);
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;
