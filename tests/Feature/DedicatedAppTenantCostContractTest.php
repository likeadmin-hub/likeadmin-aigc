<?php

namespace Tests\Feature;

use app\common\service\app\aigc_action_transfer\AigcActionTransferService;
use app\common\service\app\aigc_person_replacement\AigcPersonReplacementService;
use app\common\service\power\MarketAppCostPricingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DedicatedAppTenantCostContractTest extends TestCase
{
    public function testPlatformMarketSkuDeterminesTenantCostForDurationBilling(): void
    {
        $quote = MarketAppCostPricingService::quoteFromCatalog([
            'id' => 41,
            'upstream_app_code' => 'action_transfer',
            'upstream_api_code' => 'submit',
        ], [[
            'id' => 91,
            'status' => 1,
            'sale_status' => 1,
            'sale_points' => 1.25,
            'usage_unit' => 'second',
            'usage_unit_size' => 1,
            'locked_params' => ['mode' => 'standard'],
        ]], [
            'mode' => 'standard',
            'duration' => 6,
        ]);

        self::assertSame(1.25, $quote['platform_unit_cost']);
        self::assertSame(7.5, $quote['tenant_cost_points']);
        self::assertSame(41, $quote['market_product_id']);
        self::assertSame(91, $quote['market_sku_id']);
    }

    public function testPlatformMarketSkuDeterminesTenantCostForPerCallBilling(): void
    {
        $quote = MarketAppCostPricingService::quoteFromCatalog([
            'id' => 42,
            'upstream_app_code' => 'person_replacement',
            'upstream_api_code' => 'submit',
        ], [[
            'id' => 92,
            'status' => 1,
            'sale_status' => 1,
            'sale_points' => 18.6,
            'usage_unit' => 'per_call',
            'usage_unit_size' => 1,
            'locked_params' => ['mode' => 'max'],
        ]], [
            'mode' => 'max',
            'duration' => 40,
        ]);

        self::assertSame(1.0, $quote['quantity']);
        self::assertSame(18.6, $quote['tenant_cost_points']);
    }

    public function testPlatformSkuMatchIncludesFaceCountWhenItIsLocked(): void
    {
        $quote = MarketAppCostPricingService::quoteFromCatalog([
            'id' => 42,
            'upstream_app_code' => 'person_replacement',
            'upstream_api_code' => 'submit',
        ], [
            [
                'id' => 92,
                'status' => 1,
                'sale_status' => 1,
                'sale_points' => 18.6,
                'usage_unit' => 'per_call',
                'usage_unit_size' => 1,
                'locked_params' => ['mode' => 'max', 'face_count' => 1],
            ],
            [
                'id' => 93,
                'status' => 1,
                'sale_status' => 1,
                'sale_points' => 27.8,
                'usage_unit' => 'per_call',
                'usage_unit_size' => 1,
                'locked_params' => ['mode' => 'max', 'face_count' => 3],
            ],
        ], [
            'mode' => 'max',
            'face_count' => 3,
        ]);

        self::assertSame(93, $quote['market_sku_id']);
        self::assertSame(27.8, $quote['tenant_cost_points']);
    }

    public function testDedicatedAppsKeepTenantCostIndependentFromCendPrice(): void
    {
        $prepared = [
            'mode' => 'standard',
            'mode_label' => '标准模式',
            'face_count' => 1,
            'duration' => 6,
            'config' => ['price_matrix' => ['standard' => 9.99]],
        ];
        $marketQuote = [
            'platform_unit_cost' => 1.25,
            'tenant_cost_points' => 7.5,
            'price_source' => 'power_market_sku',
            'market_product_id' => 41,
            'market_sku_id' => 91,
            'market_snapshot' => ['platform_price' => 1.25],
        ];

        foreach ([AigcActionTransferService::class, AigcPersonReplacementService::class] as $service) {
            $estimate = $this->invoke($service, 'buildEstimateFromQuote', $prepared, $marketQuote);
            self::assertSame(7.5, $estimate['tenant_cost_points']);
            self::assertSame(59.94, $estimate['user_charge_points']);
            self::assertNotSame($estimate['tenant_cost_points'], $estimate['user_charge_points']);
            self::assertSame('power_market_sku', $estimate['price_source']);
        }
    }

    public function testTenantConsumeLogUsesTenantLedgerAndShowsMarketCostTrace(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/lists/power/TenantPowerConsumeLogLists.php');

        self::assertStringContainsString('TenantPointLog::alias', $source);
        self::assertStringContainsString("'billing_side' => '计费侧'", $source);
        self::assertStringContainsString("'price_source' => '成本来源'", $source);
        self::assertStringContainsString("'market_sku_id' => '成本SKU'", $source);
    }

    public function testBusinessConsumptionAlwaysWritesTheTenantCostLedger(): void
    {
        $root = dirname(__DIR__, 2);
        $pointSource = (string)file_get_contents($root . '/app/common/service/point/PointService.php');
        $tenantSource = (string)file_get_contents($root . '/app/common/service/point/TenantPointService.php');

        self::assertStringContainsString('TenantPointService::consume($tenantId, $tenantPoints', $pointSource);
        self::assertStringContainsString("'billing_side' => 'tenant_cost'", $pointSource);
        self::assertStringContainsString('TenantPointLog::create([', $tenantSource);
        self::assertStringContainsString('self::TYPE_CONSUME, self::ACTION_DEC', $tenantSource);
    }

    private function invoke(string $class, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }
}
