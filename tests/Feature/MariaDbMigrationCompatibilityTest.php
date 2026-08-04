<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class MariaDbMigrationCompatibilityTest extends TestCase
{
    public function testPowerConsumeLogJoinUsesAStableUtf8mb4Collation(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/lists/power/TenantPowerConsumeLogLists.php');

        self::assertStringContainsString('CONVERT(ual.source_sn USING utf8mb4) COLLATE utf8mb4_unicode_ci', $source);
        self::assertStringContainsString('CONVERT(tpl.source_sn USING utf8mb4) COLLATE utf8mb4_unicode_ci', $source);
    }

    public function testDigitalHumanJsonMigrationsDoNotCastTextToJson(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            $root . '/app/apps/aigc_digital_human/migrations/zz_20260505_model_description_fix.sql',
            $root . '/app/apps/aigc_digital_human/migrations/zz_20260609_pricing_config_repair.sql',
            $root . '/upgrade/20260609_digital_human_pricing_config_repair.sql',
            $root . '/public/upgrade/20260609_digital_human_pricing_config_repair.sql',
            $root . '/public/install/db/like.sql',
        ];

        foreach ($paths as $path) {
            $sql = (string)file_get_contents($path);
            self::assertNotSame('', $sql, 'Expected migration source: ' . $path);
            self::assertDoesNotMatchRegularExpression('/CAST\s*\([^)]*\s+AS\s+JSON\s*\)/i', $sql, $path);
            self::assertStringContainsString('IF(JSON_VALID(', $sql, $path);
        }
    }

    public function testLlmPricingRepairsOnlyReferenceColumnsConfirmedInMetadata(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            $root . '/app/apps/aigc_llm/migrations/00_20260728_three_tier_pricing_legacy_repair.sql',
            $root . '/app/apps/aigc_llm/migrations/zz_20260714_three_tier_pricing.sql',
        ];

        foreach ($paths as $path) {
            $sql = (string)file_get_contents($path);
            self::assertNotSame('', $sql, 'Expected migration source: ' . $path);
            self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $sql, $path);
            self::assertStringContainsString('@aigc_llm_input_cost', $sql, $path);
            self::assertStringContainsString('@aigc_llm_output_cost', $sql, $path);
            self::assertStringContainsString('PREPARE aigc_llm_stmt', $sql, $path);
            self::assertStringNotContainsString(' AFTER `', $sql, $path);
        }
    }
}
