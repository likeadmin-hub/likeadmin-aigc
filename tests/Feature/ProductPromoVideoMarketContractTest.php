<?php

namespace Tests\Feature;

use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\ai\MarketAppGateService;
use app\common\service\power\MarketVideoRuntimeService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductPromoVideoMarketContractTest extends TestCase
{
    public function testApplicationSalePriceCannotLowerMarketPlatformCost(): void
    {
        $quote = [
            'tenant_cost_points' => 12.5,
            'user_charge_points' => 8.5,
        ];

        $overridden = $this->invoke(MarketVideoRuntimeService::class, 'applyBillingOverride', $quote, [
            'tenant_cost_points' => 12.5,
            'user_charge_points' => 20.0,
        ]);

        self::assertSame(12.5, $overridden['tenant_cost_points']);
        self::assertSame(20.0, $overridden['user_charge_points']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('应用不能覆盖算力市场平台成本');
        $this->invoke(MarketVideoRuntimeService::class, 'applyBillingOverride', $quote, [
            'tenant_cost_points' => 12.49,
        ]);
    }

    public function testApplicationSalePriceIsPersistedAsFixedSettlementPrice(): void
    {
        $snapshot = $this->invoke(
            MarketVideoRuntimeService::class,
            'applyBillingOverrideToSnapshot',
            ['tenant_price' => 2.0, 'quantity' => 5],
            ['user_charge_points' => 17.5],
            ['user_charge_points' => 17.5]
        );

        self::assertTrue($snapshot['app_sale_price_override']);
        self::assertSame(17.5, $snapshot['fixed_user_price']);
    }

    public function testPromoMarketSpecKeepsConcreteSkuAndMarketPrice(): void
    {
        $rows = $this->invoke(
            AigcProductPromoVideoService::class,
            'buildSpecOptions',
            [
                'channels' => [[
                    'code' => 'market_video_model:42',
                    'name' => 'Market Video',
                    'qualities' => [[
                        'value' => '1080p',
                        'label' => '1080P',
                        'ratios' => [[
                            'value' => '9:16',
                            'ratio' => '9:16',
                            'duration' => 8,
                            'market_product_id' => 42,
                            'market_sku_id' => 88,
                            'model_id' => 'market_video_model:42',
                            'resource_type' => 'model_api',
                            'tenant_unit_price' => 4.0,
                        ]],
                    ]],
                ]],
            ]
        );

        $spec = $rows[0]['qualities'][0]['ratios'][0];
        self::assertSame(42, $spec['market_product_id']);
        self::assertSame(88, $spec['market_sku_id']);
        self::assertSame('market_video_model:42', $spec['model_id']);
        self::assertSame(8, $spec['duration']);
        self::assertSame(4.0, $spec['tenant_unit_price']);
        self::assertSame(0.5, $spec['unit_price']);
        self::assertSame(4.0, $spec['user_price']);
    }

    public function testPromoEstimateUsesMarketTotalInsteadOfLegacyApplicationPrice(): void
    {
        $estimate = $this->invoke(AigcProductPromoVideoService::class, 'buildEstimate', [
            'unit_price' => 3,
            'video_payload' => ['duration' => 8],
            'width' => 1080,
            'height' => 1920,
            'size_key' => '9:16',
        ], [
            'settlement_mode' => 'reserved',
            'tenant_cost_points' => 12.5,
            'user_charge_points' => 17.5,
            'user_unit_points' => 17.5,
            'market_product_id' => 42,
            'market_sku_id' => 88,
        ]);

        self::assertSame(12.5, $estimate['tenant_cost_points']);
        self::assertSame(17.5, $estimate['user_charge_points']);
        self::assertSame(17.5, $estimate['tenant_unit_price']);
        self::assertSame(2.19, $estimate['unit_price']);
    }

    public function testMarketSwitchReplacesStaleLegacyDefaultsWithMarketDefaults(): void
    {
        $aligned = $this->invoke(AigcProductPromoVideoService::class, 'alignDefaultsToOptionConfig', [
            'market_enabled' => 1,
            'default_channel' => 'legacy_video',
            'default_quality' => 'legacy_quality',
            'default_ratio' => '16:9',
            'config_json' => [],
        ], [
            'channels' => [[
                'code' => 'market_video_app:7',
                'qualities' => [[
                    'value' => '1080p',
                    'ratios' => [['value' => '9:16', 'duration' => 8]],
                ]],
            ]],
        ]);

        self::assertSame('market_video_app:7', $aligned['default_channel']);
        self::assertSame('1080p', $aligned['default_quality']);
        self::assertSame('9:16', $aligned['default_ratio']);
        self::assertSame('market_video_app:7', $aligned['config_json']['channel']);
    }

    public function testStructuredMarketInputModesExposeImageReferenceProducts(): void
    {
        self::assertTrue($this->invoke(AigcProductPromoVideoService::class, 'supportsImageReference', [
            'input_modes' => [['value' => 'text_to_video'], ['value' => 'image_reference']],
        ]));
        self::assertTrue($this->invoke(AigcProductPromoVideoService::class, 'supportsImageReference', [
            'generation_modes' => ['image_to_video'],
        ]));
        self::assertTrue($this->invoke(AigcProductPromoVideoService::class, 'supportsImageReference', [
            'generation_modes' => ['omni_reference'],
        ]));
        self::assertTrue($this->invoke(AigcProductPromoVideoService::class, 'supportsImageReference', [
            'input_modes' => ['image_reference'],
        ]));
        self::assertTrue($this->invoke(AigcProductPromoVideoService::class, 'supportsImageReference', [
            'input_modes' => [['value' => 'text_to_video']],
        ]));
        self::assertTrue($this->invoke(AigcProductPromoVideoService::class, 'supportsImageReference', [
            'supported_asset_types' => ['image'],
            'max_reference_images' => 1,
        ]));
    }

    public function testPromoUsesRuntimeCompatibleSingleImageGenerationMode(): void
    {
        self::assertSame('image_reference', $this->invoke(
            AigcProductPromoVideoService::class,
            'marketPromoGenerationMethod',
            ['generation_modes' => ['omni_reference', 'image_reference']]
        ));
        self::assertSame('image_to_video', $this->invoke(
            AigcProductPromoVideoService::class,
            'marketPromoGenerationMethod',
            ['generation_modes' => ['text_to_video', 'image_to_video']]
        ));
        self::assertSame('omni_reference', $this->invoke(
            AigcProductPromoVideoService::class,
            'marketPromoGenerationMethod',
            ['generation_modes' => ['omni_reference', 'start_end', 'multi_frame']]
        ));
        self::assertSame('text_to_video', $this->invoke(
            AigcProductPromoVideoService::class,
            'marketPromoGenerationMethod',
            ['generation_modes' => ['text_to_video']]
        ));
    }

    public function testMarketApplicationChannelCodeRetainsUpstreamApplicationSegment(): void
    {
        self::assertSame('market_video_model:174', $this->invoke(
            AigcProductPromoVideoService::class,
            'normalizeChannelCode',
            'market_video_model:174'
        ));
        self::assertSame('market_video_app:happy_horse:111', $this->invoke(
            AigcProductPromoVideoService::class,
            'normalizeChannelCode',
            'market_video_app:happy_horse:111'
        ));
    }

    public function testPromoMarketResultsAlwaysUseDurableTenantStorage(): void
    {
        self::assertTrue($this->invoke(
            MarketVideoRuntimeService::class,
            'requiresDurableResultStorage',
            'aigc_product_promo_video'
        ));
        self::assertTrue($this->invoke(
            MarketVideoRuntimeService::class,
            'requiresDurableResultStorage',
            'aigc_short_drama'
        ));
        self::assertFalse($this->invoke(
            MarketVideoRuntimeService::class,
            'requiresDurableResultStorage',
            'aigc_video'
        ));
    }

    public function testPromoUsesSelectedVideoSkuWithoutASeparateWrapperMarketGate(): void
    {
        self::assertFalse(MarketAppGateService::requiresGate('aigc_product_promo_video'));

        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/app/common/service/app/aigc_product_promo_video/AigcProductPromoVideoService.php'
        );
        self::assertStringNotContainsString('MarketAppGateService::requireMarket', $source);
        self::assertStringContainsString(
            "AigcVideoService::generateMarket(\$tenantId, \$userId, \$prepared['video_payload'], self::APP_CODE)",
            $source
        );
    }

    public function testSuccessfulSettlementClearsTransientRefreshDiagnostics(): void
    {
        $state = $this->invoke(MarketVideoRuntimeService::class, 'clearRefreshDiagnostics', [
            'run_status' => 'success',
            'billing_status' => 'settled',
            'error_code' => 'refresh_retrying',
            'error_message' => 'temporary supplier query error',
            'refresh_requested_at' => 123,
        ]);

        self::assertSame('success', $state['run_status']);
        self::assertSame('settled', $state['billing_status']);
        self::assertSame('', $state['error_code']);
        self::assertSame('', $state['error_message']);
        self::assertSame(0, $state['refresh_requested_at']);
    }

    public function testMarketSpecUsesProductLevelRatioAndDurationOptionsWhenSkuIsGeneric(): void
    {
        $selections = $this->invoke(AigcProductPromoVideoService::class, 'marketSpecSelections', [
            'ratio_options' => ['16:9', '9:16'],
            'duration_options' => [5, 10],
        ], [
            'ratio' => '',
            'duration' => 0,
            'ratio_options' => [],
            'duration_options' => [],
        ]);

        self::assertSame([
            ['ratio' => '16:9', 'duration' => 5],
            ['ratio' => '16:9', 'duration' => 10],
            ['ratio' => '9:16', 'duration' => 5],
            ['ratio' => '9:16', 'duration' => 10],
        ], $selections);
    }

    public function testMarketSwitchRejectsEmptyImageReferenceCatalog(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('暂无支持产品图片参考输入的算力市场视频规格');
        $this->invoke(AigcProductPromoVideoService::class, 'alignDefaultsToOptionConfig', [
            'market_enabled' => 1,
            'config_json' => [],
        ], ['channels' => []]);
    }

    public function testPromoConfigurationIsMarketOnly(): void
    {
        $defaults = $this->invoke(AigcProductPromoVideoService::class, 'defaults');
        self::assertSame(1, $defaults['market_enabled']);

        $config = $this->invoke(AigcProductPromoVideoService::class, 'normalizeConfigJson', [
            'channel' => 'seedance2_pro',
            'market_enabled' => 0,
        ]);
        self::assertSame(1, $config['market_enabled']);
    }

    public function testAppApiMetadataInheritsAppLevelCapabilityFields(): void
    {
        $metadata = $this->invoke(
            \app\common\service\power\PowerMarketService::class,
            'appApiMetadata',
            [
                'name' => 'Seedance 2.0 Pro',
                'description' => 'Seedance application',
                'capabilities' => [
                    'supported_asset_types' => ['image', 'video', 'audio'],
                    'input_modes' => ['image_reference'],
                    'generation_modes' => ['image_reference'],
                    'max_reference_images' => 9,
                    'max_reference_videos' => 3,
                    'max_reference_audios' => 3,
                    'max_reference_assets' => 15,
                    'supports_vision' => true,
                ],
            ],
            [
                'code' => 'create',
                'name' => 'Create',
                'supported_asset_types' => [],
                'input_modes' => [],
                'generation_modes' => [],
                'capabilities' => [],
            ]
        );

        self::assertSame(['image', 'video', 'audio'], $metadata['supported_asset_types']);
        self::assertSame(['image_reference'], $metadata['input_modes']);
        self::assertSame(['image_reference'], $metadata['generation_modes']);
        self::assertSame(9, $metadata['max_reference_images']);
        self::assertSame(3, $metadata['max_reference_videos']);
        self::assertSame(3, $metadata['max_reference_audios']);
        self::assertSame(15, $metadata['max_reference_assets']);
    }

    public function testSeedance2ProSkuIsRecognizedAsImageReferenceInput(): void
    {
        self::assertSame('image_reference', $this->invoke(
            MarketVideoRuntimeService::class,
            'skuInputMode',
            ['model' => 'seedance2_pro']
        ));
        self::assertTrue($this->invoke(
            MarketVideoRuntimeService::class,
            'skuSupportsInputMode',
            'image_reference',
            'image_reference',
            ['resource_type' => 'app_api', 'upstream_app_code' => 'seedance2_pro'],
            ['model' => 'seedance2_pro']
        ));
    }

    public function testLegacyVideoBillingOverrideEntryPointIsRemoved(): void
    {
        self::assertFalse(method_exists(AigcVideoService::class, 'generateWithBillingOverride'));
    }

    public function testPromoRejectsActualUsageMarketSkuBeforeCharging(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('产品宣传视频暂不支持按实际用量计费的视频 SKU');
        $this->invoke(AigcProductPromoVideoService::class, 'buildEstimate', [
            'market_enabled' => true,
            'unit_price' => 3,
            'video_payload' => ['duration' => 8],
        ], ['settlement_mode' => 'actual_usage']);
    }

    public function testMarketVideoAppGateSeparatesGenerationAndLifecycleApis(): void
    {
        self::assertTrue($this->invoke(MarketVideoRuntimeService::class, 'isVideoGenerationApi', [
            'upstream_app_code' => 'full_video',
            'upstream_api_code' => 'submit',
        ]));
        self::assertTrue($this->invoke(MarketVideoRuntimeService::class, 'isVideoGenerationApi', [
            'upstream_app_code' => 'seedance',
            'upstream_api_code' => 'create',
        ]));
        self::assertFalse($this->invoke(MarketVideoRuntimeService::class, 'isVideoGenerationApi', [
            'upstream_app_code' => 'full_video',
            'upstream_api_code' => 'query',
        ]));
        self::assertFalse($this->invoke(MarketVideoRuntimeService::class, 'isVideoGenerationApi', [
            'upstream_app_code' => 'seedance',
            'upstream_api_code' => 'createAsset',
        ]));
        self::assertTrue($this->invoke(MarketVideoRuntimeService::class, 'hasNonVideoAppIdentity', [
            'upstream_app_code' => 'music_generation',
            'name' => 'Music create',
        ]));
        self::assertTrue($this->invoke(MarketVideoRuntimeService::class, 'hasNonVideoAppIdentity', [
            'upstream_app_code' => 'nano_banana',
            'name' => 'Image create',
        ]));
    }

    public function testMarketVideoAppGateUsesNormalizedApplicationCategory(): void
    {
        self::assertTrue($this->invoke(MarketVideoRuntimeService::class, 'isVideoAppCategory', [
            'source_payload' => ['market_metadata' => ['category_code' => 'video']],
        ]));
        self::assertFalse($this->invoke(MarketVideoRuntimeService::class, 'isVideoAppCategory', [
            'source_payload' => ['market_metadata' => ['category_code' => 'other']],
        ]));
    }

    public function testMetadataFreeAppApiDoesNotInventTextToVideoCapability(): void
    {
        self::assertSame([], $this->invoke(
            MarketVideoRuntimeService::class,
            'inputModes',
            \app\common\service\power\PowerMarketService::TYPE_APP_API,
            ['resource_type' => \app\common\service\power\PowerMarketService::TYPE_APP_API, 'upstream_app_code' => 'music_generation'],
            []
        ));
    }

    public function testHappyHorseCreateAliasIsShadowedBySubmitOption(): void
    {
        $create = [
            'upstream_app_code' => 'happy_horse',
            'upstream_api_code' => 'create',
            'status' => 1,
        ];
        $submit = [
            'upstream_app_code' => 'happy_horse',
            'upstream_api_code' => 'submit',
            'status' => 1,
        ];

        self::assertTrue($this->invoke(
            MarketVideoRuntimeService::class,
            'isShadowedAppApi',
            $create,
            [$create, $submit]
        ));
        self::assertFalse($this->invoke(
            MarketVideoRuntimeService::class,
            'isShadowedAppApi',
            $create,
            [$create]
        ));
    }

    public function testPromoTaskSchemaAndUpgradePathsStayInParity(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            $root . '/app/apps/aigc_product_promo_video/migrations/install.sql',
            $root . '/upgrade/20260805_aigc_product_promo_video_market.sql',
            $root . '/public/upgrade/20260805_aigc_product_promo_video_market.sql',
        ];

        foreach ($paths as $path) {
            $sql = (string)file_get_contents($path);
            self::assertNotSame('', $sql, 'Expected migration source: ' . $path);
            foreach (['market_enabled', 'app_task_id', 'consumption_id', 'market_product_id', 'market_sku_id', 'pricing_snapshot', 'billing_status'] as $column) {
                self::assertStringContainsString($column, $sql, $path);
            }
        }
    }

    private function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
