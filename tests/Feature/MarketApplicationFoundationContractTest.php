<?php

namespace Tests\Feature;

use app\common\service\ai\MarketAppGateService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use app\common\service\power\MarketGenerationCatalogService;
use app\common\service\power\PowerMarketService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class MarketApplicationFoundationContractTest extends TestCase
{
    public function testDisabledDecisionAlwaysUsesLegacyRoute(): void
    {
        $decision = $this->gateMethod('decision', MarketAppGateService::LEGACY, 'market_disabled');

        self::assertSame(MarketAppGateService::LEGACY, $decision['route']);
        self::assertFalse($decision['market']);
        self::assertSame('market_disabled', $decision['reason']);
    }

    public function testPartialRolloutDecisionIsExplicitRatherThanFallback(): void
    {
        $decision = $this->gateMethod('decision', MarketAppGateService::MARKET, 'enabled');

        self::assertSame(MarketAppGateService::MARKET, $decision['route']);
        self::assertTrue($decision['market']);
    }

    public function testManagedAppsNeedAnExplicitMarketGate(): void
    {
        self::assertFalse(MarketAppGateService::requiresGate('aigc_product_promo_video'));
        self::assertTrue(MarketAppGateService::requiresGate('aigc_music'));
        self::assertTrue(MarketAppGateService::requiresGate('aigc_person_replacement'));
        self::assertFalse(MarketAppGateService::requiresGate('aigc_video'));

        $this->expectException(RuntimeException::class);
        MarketAppGateService::requireMarket(0, 1, 'aigc_music');
    }

    public function testApplicationRuntimeMapsKnownAdapters(): void
    {
        self::assertSame(
            'app\\common\\service\\power\\MarketNanoBananaAppRuntimeService',
            MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'nano_banana'])
        );
        self::assertSame(
            'app\\common\\service\\power\\MarketMusicAppRuntimeService',
            MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'music_generation'])
        );
    }

    public function testUnknownApplicationAdapterIsRejected(): void
    {
        $this->expectException(Exception::class);
        MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'unsupported_app']);
    }

    public function testGenericCreateApiDoesNotSilentlyBecomeMusicAdapter(): void
    {
        $this->expectException(Exception::class);
        MarketApplicationApiRuntimeService::adapterForSelection([
            'resource_type' => 'app_api',
            'upstream_app_code' => 'full_video',
            'api_code' => 'create',
        ]);
    }

    public function testCatalogExposesOnlyTheThreeGenerationTypes(): void
    {
        self::assertSame(['text', 'image', 'video'], MarketGenerationCatalogService::TYPES);
    }

    public function testImageModelSelectorsOnlyUseBackendModelApis(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = [
            (string)file_get_contents($root . '/app/common/service/app/aigc_image/AigcImageChannelService.php'),
            (string)file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/model/CanvasModelRouterService.php'),
            (string)file_get_contents($root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php'),
        ];

        foreach ($sources as $source) {
            self::assertStringNotContainsString('MarketNanoBananaAppRuntimeService::options', $source);
            self::assertStringContainsString('MarketImageModelRuntimeService::', $source);
        }
    }

    public function testTextFallbackIsDisabled(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/common/service/power/MarketTextModelRuntimeService.php');
        self::assertStringNotContainsString('fallbackModel', $source);
        self::assertStringNotContainsString('_market_text_fallback_used', $source);
    }

    public function testApplicationCategoryIsFlattenedAndReturnedForImageVideoAndAudioApps(): void
    {
        $samples = [
            ['category_id' => 2, 'category_code' => 'image', 'category_name' => '图片生成'],
            ['category_id' => 3, 'category_code' => 'video', 'category_name' => '视频生成'],
            ['category_id' => 4, 'category_code' => 'audio', 'category_name' => '音频生成'],
        ];

        foreach ($samples as $sample) {
            $metadata = $this->invoke(PowerMarketService::class, 'appApiMetadata', [
                'code' => 'sample_app',
                'name' => 'Sample app',
                'category_id' => $sample['category_id'],
                'category_code' => $sample['category_code'],
                'category_name' => $sample['category_name'],
            ], [
                'code' => 'submit',
                'name' => 'Submit',
                'category_id' => 99,
                'category_code' => 'endpoint',
                'category_name' => 'Endpoint category',
            ]);

            self::assertSame($sample['category_id'], $metadata['category_id']);
            self::assertSame($sample['category_code'], $metadata['category_code']);
            self::assertSame($sample['category_name'], $metadata['category_name']);

            $formatted = PowerMarketService::formatProduct([
                'resource_type' => PowerMarketService::TYPE_APP_API,
                'source_payload' => ['market_metadata' => $metadata],
            ]);
            self::assertSame($sample['category_id'], $formatted['category_id']);
            self::assertSame($sample['category_code'], $formatted['category_code']);
            self::assertSame($sample['category_name'], $formatted['category_name']);
            self::assertSame($sample['category_code'], $formatted['category']['code']);
        }
    }

    public function testLegacyApplicationSnapshotDoesNotTreatAppIdentityAsCategory(): void
    {
        $formatted = PowerMarketService::formatProduct([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'source_payload' => [
                'resource' => [
                    'id' => 24,
                    'code' => 'full_video',
                    'name' => '全能视频生成',
                ],
                'market_metadata' => [
                    'upstream_app_metadata' => [
                        'id' => 24,
                        'code' => 'full_video',
                        'name' => '全能视频生成',
                    ],
                ],
            ],
        ]);

        self::assertSame(0, $formatted['category_id']);
        self::assertSame('', $formatted['category_code']);
        self::assertSame('', $formatted['category_name']);
    }

    public function testApplicationCategoryAliasesUseTheSharedFrontendContract(): void
    {
        $metadata = $this->invoke(PowerMarketService::class, 'appApiMetadata', [
            'code' => 'sample_app',
            'category_code' => 'video_generation',
            'category_name' => '视频生成服务',
        ], [
            'code' => 'submit',
        ]);

        self::assertSame(3, $metadata['category_id']);
        self::assertSame('video', $metadata['category_code']);
        self::assertSame('视频生成', $metadata['category_name']);
        self::assertSame([
            ['id' => 1, 'code' => 'text', 'name' => '文本生成'],
            ['id' => 2, 'code' => 'image', 'name' => '图片生成'],
            ['id' => 3, 'code' => 'video', 'name' => '视频生成'],
            ['id' => 4, 'code' => 'audio', 'name' => '音频生成'],
            ['id' => 5, 'code' => 'other', 'name' => '其他'],
        ], PowerMarketService::appCategories());
    }

    public function testFoundationSqlExistsAcrossAllRequiredSurfaces(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            '/public/install/db/like.sql',
            '/upgrade/20260806_market_application_foundation.sql',
            '/public/upgrade/20260806_market_application_foundation.sql',
        ] as $path) {
            $source = (string)file_get_contents($root . $path);
            self::assertStringContainsString('la_ai_market_app_gate', $source);
        }
    }

    public function testMarketTaskTraceFieldsCoverEveryMigrationUnit(): void
    {
        $root = dirname(__DIR__, 2);
        $taskTables = [
            'aigc_product_promo_video' => 'la_aigc_product_promo_video_task',
            'aigc_music' => 'la_aigc_music_task',
            'aigc_digital_human' => 'la_aigc_digital_human_task',
            'image_human' => 'la_image_human_task',
            'smart_clip' => 'la_smart_clip_task',
            'aigc_action_transfer' => 'la_aigc_action_transfer_task',
            'aigc_person_replacement' => 'la_aigc_person_replacement_task',
        ];
        $fields = [
            'app_task_id', 'consumption_id', 'market_product_id', 'market_sku_id',
            'pricing_snapshot', 'idempotency_key', 'market_request_id', 'market_retry_count',
            'billing_status', 'market_error_code',
        ];

        foreach ($taskTables as $appCode => $table) {
            $source = (string)file_get_contents($root . '/app/apps/' . $appCode . '/migrations/install.sql');
            $this->assertTaskFields($source, $table, $fields);
        }

        $installSource = (string)file_get_contents($root . '/public/install/db/like.sql');
        foreach (array_intersect_key($taskTables, array_flip([
            'aigc_product_promo_video', 'aigc_digital_human', 'aigc_action_transfer', 'aigc_person_replacement',
        ])) as $table) {
            $this->assertTaskFields($installSource, $table, $fields);
        }

        $serverUpgrade = (string)file_get_contents($root . '/upgrade/20260806_market_task_trace.sql');
        $publicUpgrade = (string)file_get_contents($root . '/public/upgrade/20260806_market_task_trace.sql');
        self::assertSame($serverUpgrade, $publicUpgrade);
        foreach ($taskTables as $table) {
            self::assertStringContainsString($table, $serverUpgrade);
        }
        foreach ($fields as $field) {
            self::assertStringContainsString($field, $serverUpgrade);
        }
    }

    private function assertTaskFields(string $source, string $table, array $fields): void
    {
        $start = strpos($source, 'CREATE TABLE IF NOT EXISTS `' . $table . '`');
        self::assertNotFalse($start, 'Task table is missing: ' . $table);
        $block = substr($source, $start, 8000);
        foreach ($fields as $field) {
            self::assertStringContainsString('`' . $field . '`', $block, $table . ' missing ' . $field);
        }
    }

    private function gateMethod(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MarketAppGateService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    private function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
