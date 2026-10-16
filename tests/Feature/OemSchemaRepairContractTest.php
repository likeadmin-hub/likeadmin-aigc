<?php

namespace tests\Feature;

use app\common\service\database\RuntimeMigrationPlan;
use PHPUnit\Framework\TestCase;

final class OemSchemaRepairContractTest extends TestCase
{
    public function testRepairKeepsPublishedMigrationsIntactAndCoversAllMissingOemState(): void
    {
        $root = dirname(__DIR__, 2);
        $repair = file_get_contents($root . '/upgrade/20261016_oem_schema_repair.sql');
        self::assertSame($repair, file_get_contents($root . '/public/upgrade/20261016_oem_schema_repair.sql'));
        foreach (['20261013_oem_self_service_v2.sql', '20261013_oem_self_service_v2_operations.sql', '20261014_oem_maintenance.sql', '20261014_oem_payment_reconcile.sql', '20261014_oem_refund_recovery.sql'] as $file) {
            self::assertStringContainsString(file_get_contents($root . '/upgrade/' . $file), $repair);
        }
        $plan = RuntimeMigrationPlan::build($repair);
        self::assertNotEmpty($plan['prepare']);
        self::assertNotEmpty($plan['complete']);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($repair));
        self::assertStringNotContainsString('TRUNCATE', strtoupper($repair));
    }
}
