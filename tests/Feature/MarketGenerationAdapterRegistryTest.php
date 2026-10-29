<?php

namespace Tests\Feature;

use app\common\service\power\MarketGenerationAdapterRegistry as Registry;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketGenerationAdapterRegistryTest extends TestCase
{
    public function testModelAndApplicationWithTheSameNameKeepIndependentAdapters(): void
    {
        self::assertSame(MarketImageModelRuntimeService::class, Registry::runtime('image', ['resource_type' => 'model', 'model_id' => 'market_image_model:93']));
        self::assertSame(MarketNanoBananaAppRuntimeService::class, Registry::runtime('image', ['resource_type' => 'app_api', 'upstream_app_code' => 'nano_banana']));
        self::assertSame('/api/v1/tasks', Registry::transport(['resource_type' => 'model', 'model_code' => 'nano-banana-2'], 'submit')['path']);
        self::assertSame('/api/v1/apps/nano_banana/submit', Registry::transport(['resource_type' => 'app_api', 'app_code' => 'nano_banana'], 'submit')['path']);
    }

    public function testVideoToolsUseTheSameRegisteredRuntime(): void
    {
        $tools = [
            [\app\common\service\app\aigc_video\AigcVideoService::class, 'marketRuntime'],
            [\app\common\service\app\aigc_canvas\CanvasModelRouterService::class, 'videoMarketRuntime'],
            [\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'marketVideoRuntime'],
        ];
        foreach (['market_video_model:1' => MarketVideoModelRuntimeService::class, 'market_video_app:2' => MarketVideoAppRuntimeService::class] as $id => $expected) {
            foreach ($tools as [$class, $method]) self::assertSame($expected, (new ReflectionMethod($class, $method))->invoke(null, ['model_id' => $id]));
        }
    }

    public function testRegisteredApplicationProtocolsPreserveQueryMethodsAndAssetPreparation(): void
    {
        foreach (['seedance' => 'GET', 'wan' => 'GET', 'grok_video' => 'GET', 'happy_horse' => 'POST', 'full_video' => 'POST'] as $app => $method) {
            $snapshot = ['resource_type' => 'app_api', 'app_code' => $app, 'api_code' => 'submit'];
            self::assertSame(MarketVideoAppRuntimeService::class, MarketApplicationApiRuntimeService::adapterForSelection($snapshot));
            $query = Registry::transport($snapshot, 'query', 'task/id');
            self::assertSame($method, $query['method']);
            self::assertSame($method === 'POST' ? ['task_id' => 'task/id'] : [], $query['payload']);
            self::assertSame('/api/v1/apps/' . $app . '/query' . ($method === 'GET' ? '?task_id=task%2Fid' : ''), $query['path']);
            self::assertSame($app === 'seedance', Registry::requiresAssetPreparation($snapshot));
        }
    }

    /** @dataProvider incompatibleIdentities */
    public function testConflictingResourceIdentityIsRejected(array $selection): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::runtime('video', $selection);
    }

    public static function incompatibleIdentities(): array
    {
        return [[['resource_type' => 'model', 'channel' => 'market_video_app:1']],
            [['resource_type' => 'app_api', 'model_id' => 'market_video_model:1']],
            [['model_id' => 'market_video_model:1', 'channel' => 'market_video_app:1']],
            [['resource_type' => 'arbitrary-runtime-class']],
            [['model_id' => 'market_image_model:1']],
            [['resource_type' => 'model', 'model_type' => 'image']]];
    }

    public function testUnknownApplicationNeverFallsBackToWanTransport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \app\common\service\power\MarketVideoRuntimeService::prepareRequest(['resource_type' => 'app_api', 'app_code' => 'not-registered'], ['prompt' => 'fixture']);
    }

    public function testVideoApplicationFacadeNeverIgnoresAnImageStyleBatchQuantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarketApplicationApiRuntimeService::quote(1, ['app_code' => 'full_video'], 4);
    }

    public function testRegisteredVideoApplicationCannotBecomeAnImageModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Registry::runtime('image', ['resource_type' => 'app_api', 'app_code' => 'full_video']);
    }
}
