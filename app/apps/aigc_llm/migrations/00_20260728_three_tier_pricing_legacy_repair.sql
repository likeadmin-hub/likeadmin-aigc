-- Run before install.sql so existing LLM tables have the required price columns.
SET @aigc_llm_table = 'la_aigc_llm_model';

SET @aigc_llm_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table)
        AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table AND COLUMN_NAME = 'platform_input_unit_price'),
        CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_input_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台向租户收取的输入售价，点/百万Token'' AFTER `platform_output_unit_cost`'),
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
        CONCAT('ALTER TABLE `', @aigc_llm_table, '` ADD COLUMN `platform_output_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台向租户收取的输出售价，点/百万Token'' AFTER `platform_input_unit_price`'),
        'SELECT 1'
    )
);
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;

SET @aigc_llm_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_llm_table),
        CONCAT(
            'UPDATE `', @aigc_llm_table, '` SET `platform_input_unit_price` = CASE ',
            'WHEN `platform_input_unit_price` = 0 THEN COALESCE(NULLIF(`platform_input_unit_cost`, 0), `platform_unit_cost`, 0) ELSE `platform_input_unit_price` END, ',
            '`platform_output_unit_price` = CASE ',
            'WHEN `platform_output_unit_price` = 0 THEN COALESCE(NULLIF(`platform_output_unit_cost`, 0), `platform_unit_cost`, 0) ELSE `platform_output_unit_price` END, ',
            '`update_time` = UNIX_TIMESTAMP()'
        ),
        'SELECT 1'
    )
);
PREPARE aigc_llm_stmt FROM @aigc_llm_sql;
EXECUTE aigc_llm_stmt;
DEALLOCATE PREPARE aigc_llm_stmt;
