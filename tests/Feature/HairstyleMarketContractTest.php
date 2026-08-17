<?php

namespace Tests\Feature;

use app\common\service\app\aigc_hairstyle\AigcHairstyleService;
use app\common\service\power\MarketImageModelRuntimeService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class HairstyleMarketContractTest extends TestCase
{
    public function testStaleLegacyDefaultsAreAlignedToAnEligibleMarketSku(): void
    {
        $options = $this->options();
        $aligned = $this->invoke(AigcHairstyleService::class, 'alignMarketConfig', [
            'channel' => 'legacy-image',
            'quality' => 'legacy-quality',
            'ratio' => '16:9',
        ], $options);

        self::assertSame('market_image_model:100', $aligned['channel']);
        self::assertSame('2k', $aligned['quality']);
        self::assertSame('1:1', $aligned['ratio']);
    }

    public function testLegacyModelIdentifierKeepsTheSelectedMarketModel(): void
    {
        $aligned = $this->invoke(AigcHairstyleService::class, 'alignMarketConfig', [
            'channel' => 'market_image_model100',
            'quality' => '2k',
            'ratio' => '9:16',
        ], $this->options());

        self::assertSame('market_image_model:100', $aligned['channel']);
        self::assertSame('9:16', $aligned['ratio']);
    }

    public function testSelectedMarketSkuIsPreservedForSubmission(): void
    {
        $spec = $this->invoke(AigcHairstyleService::class, 'marketSpec', $this->options(), [
            'channel' => 'market_image_model:100',
            'quality' => '2k',
            'ratio' => '9:16',
        ]);

        self::assertSame('market_image_model:100', $spec['model_id']);
        self::assertSame(100, $spec['market_product_id']);
        self::assertSame(301, $spec['market_sku_id']);
        self::assertSame('9:16', $spec['ratio']);
    }

    public function testHairstyleEstimateUsesMarketTotalsWithoutDoubleCharging(): void
    {
        $estimate = $this->invoke(AigcHairstyleService::class, 'buildHairstyleEstimate', [
            'operation' => 'hair_style',
            'operation_label' => 'Hair style',
            'unit_price' => 10.0,
            'image_payload' => ['quantity' => 2],
        ], [
            'quantity' => 2,
            'settlement_mode' => 'reserved',
            'platform_unit_cost' => 3.5,
            'tenant_unit_price' => 10.0,
            'tenant_cost_points' => 7.0,
            'user_charge_points' => 20.0,
        ]);

        self::assertSame(7.0, $estimate['tenant_cost_points']);
        self::assertSame(20.0, $estimate['user_charge_points']);
        self::assertSame(20.0, $estimate['display_points']);
    }

    public function testHairstyleRejectsActualUsageSkuBeforeReservation(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('actual-usage image SKUs');
        $this->invoke(AigcHairstyleService::class, 'buildHairstyleEstimate', [
            'operation' => 'hair_style',
            'operation_label' => 'Hair style',
            'unit_price' => 10.0,
            'image_payload' => ['quantity' => 1],
        ], ['settlement_mode' => 'actual_usage']);
    }

    public function testApplicationSalePriceCannotOverrideMarketPlatformCost(): void
    {
        $quote = $this->invoke(MarketImageModelRuntimeService::class, 'applyBillingOverride', [
            'quantity' => 2,
            'tenant_unit_points' => 4.0,
            'user_unit_points' => 4.0,
            'tenant_cost_points' => 8.0,
            'user_charge_points' => 8.0,
        ], ['user_unit_price' => 12.0]);

        self::assertSame(8.0, $quote['tenant_cost_points']);
        self::assertSame(24.0, $quote['user_charge_points']);

        $this->expectException(Exception::class);
        $this->invoke(MarketImageModelRuntimeService::class, 'applyBillingOverride', $quote, [
            'tenant_unit_cost' => 3.99,
        ]);
    }

    public function testApplicationSalePriceIsPersistedForSettlement(): void
    {
        $snapshot = $this->invoke(MarketImageModelRuntimeService::class, 'snapshotWithBillingOverride', [
            'product' => [
                'id' => 100,
                'upstream_model_code' => 'test',
                'upstream_channel_code' => 'test',
                'source_payload' => [],
            ],
            'sku' => [
                'id' => 301,
                'sku_key' => 'test',
                'locked_params' => [],
                'usage_unit' => 'image',
                'upstream_price' => 1,
                'sale_points' => 4,
            ],
            'tenant_price' => 4,
            'reference_limit' => 2,
        ], ['user_unit_price' => 12.0]);

        self::assertTrue($snapshot['app_sale_price_override']);
        self::assertSame(12.0, $snapshot['fixed_user_unit_price']);
    }

    public function testHairstyleNoLongerUsesLegacyImageProviderPath(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_hairstyle/AigcHairstyleService.php');
        self::assertStringNotContainsString('AigcImageChannelService', $source);
        self::assertStringNotContainsString('generateWithBillingOverride', $source);
        self::assertStringNotContainsString('AigcImageService::retryTask', $source);
        self::assertStringContainsString('generateMarketModelWithBillingOverride', $source);
        self::assertStringContainsString('operationFromPrompt', $source);
    }

    private function options(): array
    {
        return [
            'channels' => [[
                'code' => 'market_image_model:100',
                'qualities' => [[
                    'value' => '2k',
                    'ratios' => [
                        ['value' => '1:1', 'ratio' => '1:1', 'model_id' => 'market_image_model:100', 'market_product_id' => 100, 'market_sku_id' => 300],
                        ['value' => '9:16', 'ratio' => '9:16', 'model_id' => 'market_image_model:100', 'market_product_id' => 100, 'market_sku_id' => 301],
                    ],
                ]],
            ]],
        ];
    }

    private function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
