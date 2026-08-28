<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaStyleDefaultContractTest extends TestCase
{
    public function testDefaultMarkerProtectsSeededStylesAndLeavesCustomStylesDeletable(): void
    {
        self::assertTrue($this->isDefault([
            'tenant_id' => 12,
            'is_default' => 1,
        ]));
        self::assertFalse($this->isDefault([
            'tenant_id' => 12,
            'is_default' => 0,
        ]));
        self::assertTrue($this->isDefault([
            'tenant_id' => 0,
        ]));

        $root = dirname(__DIR__, 2);
        $service = (string)file_get_contents($root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
        self::assertStringContainsString('if (self::isDefaultStyleRow($row->toArray()))', $service);
    }

    public function testAdminStylePayloadExposesDefaultMarker(): void
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, 'formatAdminStyle');
        $reflection->setAccessible(true);
        $payload = $reflection->invoke(null, [
            'id' => 1,
            'tenant_id' => 12,
            'name' => '默认画风',
            'image' => '',
            'description' => '',
            'is_new' => 0,
            'is_default' => 1,
            'status' => 1,
            'sort' => 1,
            'create_time' => 0,
            'update_time' => 0,
        ]);

        self::assertSame(1, $payload['is_default']);
    }

    public function testStyleDefaultMigrationAddsAndBackfillsProtection(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents($root . '/app/apps/aigc_short_drama/migrations/upgrade_20260828_style_default_guard.sql');
        $install = (string)file_get_contents($root . '/app/apps/aigc_short_drama/migrations/install.sql');

        self::assertStringContainsString('ADD COLUMN `is_default`', $migration);
        self::assertStringContainsString('SET `is_default` = 1 WHERE `tenant_id` = 0', $migration);
        self::assertStringContainsString('SET target.`is_default` = 1', $migration);
        self::assertStringContainsString('public_style.`description` = target.`description`', $migration);
        self::assertStringContainsString('`is_default` tinyint NOT NULL DEFAULT 0', $install);
        self::assertStringContainsString('seed.`is_new`, 1, seed.`status`', $install);
    }

    private function isDefault(array $row): bool
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, 'isDefaultStyleRow');
        $reflection->setAccessible(true);
        return (bool)$reflection->invoke(null, $row);
    }
}
