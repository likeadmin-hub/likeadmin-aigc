<?php

use app\platformapi\lists\tenant\TenantPowerConsumeLists;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PlatformTenantPowerConsumeContractTest extends TestCase
{
    public function testPlatformListReadsTenantCostLedgerOnly(): void
    {
        $source = $this->read('app/platformapi/lists/tenant/TenantPowerConsumeLists.php');

        $this->assertStringContainsString('TenantPointLog::withoutGlobalScope()', $source);
        $this->assertStringContainsString("TenantPointService::TYPE_CONSUME", $source);
        $this->assertStringContainsString("TenantPointService::ACTION_DEC", $source);
        $this->assertStringContainsString("->leftJoin('tenant t'", $source);
        $this->assertStringContainsString("'billing_side_text'", $source);
        $this->assertStringContainsString("'租户成本'", $source);
        $this->assertStringContainsString("'user_change_amount_text'", $source);
    }

    public function testPlatformEndpointAndMenuAreRegistered(): void
    {
        $controller = $this->read('app/platformapi/controller/tenant/PowerConsumeController.php');
        $rootUpgrade = $this->read('upgrade/20260820_platform_tenant_power_consume.sql');
        $upgrade = $this->read('public/upgrade/20260820_platform_tenant_power_consume.sql');
        $install = $this->read('public/install/db/like.sql');
        $router = $this->read('public/platform/assets/index-DH0WGi9f.js');

        $this->assertStringContainsString('new TenantPowerConsumeLists()', $controller);
        $this->assertStringContainsString('tenant.power_consume/lists', $upgrade);
        $this->assertSame($rootUpgrade, $upgrade);
        $this->assertStringContainsString('tenant/power_consume/index', $upgrade);
        $this->assertStringContainsString('core_tenant_power_consume_platform', $install);
        $this->assertStringContainsString('/src/views/tenant/power_consume/index.vue', $router);
        $this->assertStringContainsString('tenant-power-consume-D1b8mQ2r.js', $router);
    }

    public function testPageMakesTenantCostAndConsumerChargeDistinct(): void
    {
        $source = $this->read('public/platform/assets/tenant-power-consume-D1b8mQ2r.js');

        $this->assertStringContainsString('/tenant.power_consume/lists', $source);
        $this->assertStringContainsString('租户成本', $source);
        $this->assertStringContainsString('C端扣费', $source);
        $this->assertStringContainsString('不参与租户成本核算', $source);
        $this->assertStringContainsString('price_source_text', $source);
    }

    public function testPageShowsAContinuousPagedSequenceColumn(): void
    {
        $source = $this->read('public/platform/assets/tenant-power-consume-D1b8mQ2r.js');

        $this->assertStringContainsString('label: "序号"', $source);
        $this->assertStringContainsString('type: "index"', $source);
        $this->assertStringContainsString('(Number(pager.page) - 1) * Number(pager.size) + index + 1', $source);
    }

    public function testPlatformConsumeTimeSupportsOrmDateStringsAndTimestamps(): void
    {
        $method = new ReflectionMethod(TenantPowerConsumeLists::class, 'formatTime');
        $method->setAccessible(true);

        $timestamp = strtotime('2026-08-18 10:11:40');
        $this->assertSame('2026-08-18 10:11:40', $method->invoke(null, '2026-08-18 10:11:40'));
        $this->assertSame('2026-08-18 10:11:40', $method->invoke(null, $timestamp));
        $this->assertSame('-', $method->invoke(null, null));
    }

    private function read(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
