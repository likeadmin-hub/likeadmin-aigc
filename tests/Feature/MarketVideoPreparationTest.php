<?php

namespace Tests\Feature;

use app\common\service\power\MarketGenerationMeter;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketCreationParameters;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** No HTTP, media uploads, tasks or point writes. */
class MarketVideoPreparationTest extends TestCase
{
    private function metadata(): array
    {
        return ['params_schema' => ['properties' => [
            'prompt' => ['type' => 'string'], 'duration' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
            'resolution' => ['type' => 'string', 'enum' => ['720P', '1080P']],
            'aspect_ratio' => ['type' => 'string', 'enum' => ['16:9', '1:1']],
            'image_url' => ['type' => 'string'], 'seed' => ['type' => 'integer', 'minimum' => 0],
            'generate_audio' => ['type' => 'boolean'],
        ], 'required' => ['prompt', 'duration', 'image_url']],
            'material_capabilities' => ['modes' => [['code' => 'image_to_video', 'view_key' => 'first', 'method' => 'image_to_video',
                'fields' => [['field' => 'image_url', 'kind' => 'image', 'role' => 'first', 'required' => true, 'minimum' => 1, 'maximum' => 1]]]]]];
    }

    private function snapshot(): array
    {
        return ['resource_type' => 'model', 'model_code' => 'new-declared-video', 'channel_code' => 'fixture',
            'locked_params' => [], 'market_metadata' => $this->metadata(), 'billing_meter' => null];
    }

    private function request(): array
    {
        return ['prompt' => 'Offline fixture', 'channel' => 'fixture', 'quality' => '720p', 'ratio' => '16:9', 'duration' => 4,
            'generation_method' => 'image_to_video', 'material_mode' => 'first',
            'reference_images' => ['https://fixture.invalid/frame.png'], 'advanced_params' => ['seed' => 0, 'generate_audio' => false]];
    }

    public function testNewVideoDeclarationCompilesAcrossStandaloneCanvasAndShortDrama(): void
    {
        $model = ['value' => 'fixture', 'resource_type' => 'model', 'params_schema' => $this->metadata()['params_schema']];
        $input = $this->request();
        $configured = MarketCreationParameters::applyOptions($input, [$model], 'video');
        $canvas = array_replace($configured, (new ReflectionMethod(AigcCanvasService::class, 'normalizeVideoParams'))->invoke(null, $configured, 0, 0, false));
        $drama = (new ReflectionMethod(ShortDramaCanvasService::class, 'generationPayload'))->invoke(null, 'video', $input, 0, 0, 1, false);
        $expected = null;
        foreach ([$input, $canvas, $drama] as $request) {
            $request = MarketCreationParameters::applyOptions($request, [$model], 'video');
            $native = Videos::prepareRequest($this->snapshot(), $request)->nativeInput;
            $expected ??= $native;
            self::assertSame($expected, $native);
            self::assertSame('720P', $native['resolution']);
            self::assertSame(0, $native['seed']);
            self::assertFalse($native['generate_audio']);
        }
    }

    /** @dataProvider outputMeters */
    public function testQuoteAndReservationMeterPreciselyThePreparedSubmission(array $source, string $unit, float $expectedQuantity, float $expectedPrice): void
    {
        $market = ['product' => ['id' => 991001, 'resource_type' => 'model', 'model_type' => 'video', 'upstream_model_code' => 'new-declared-video',
            'upstream_channel_code' => 'fixture', 'upstream_app_code' => '', 'upstream_api_code' => '', 'source_payload' => ['market_metadata' => $this->metadata()]],
            'sku' => ['id' => 991002, 'sku_key' => 'fixture', 'locked_params' => [], 'usage_unit' => $unit, 'usage_unit_size' => 1,
                'upstream_price' => 2.5, 'sale_points' => 2.5, 'source_payload' => $source], 'tenant_price' => 2.5];
        $request = $this->request();
        $prepare = new ReflectionMethod(Videos::class, 'prepareMarketRequest');
        $quantity = new ReflectionMethod(Videos::class, 'quantity');
        $args = [&$market, $request]; $quoted = $prepare->invokeArgs(null, $args);
        $args = [&$market, $request, $quoted]; $count = $quantity->invokeArgs(null, $args);
        $snapshot = (new ReflectionMethod(Videos::class, 'snapshot'))->invoke(null, $market, $count);
        $submitted = Videos::prepareRequest($snapshot, $request);
        self::assertSame($quoted->nativeInput, $submitted->nativeInput);
        self::assertSame($quoted->meterContext, $snapshot['meter_context']);
        self::assertSame($expectedQuantity, $count);
        self::assertSame($expectedPrice, MarketGenerationMeter::price(2.5, $count, $snapshot));
    }

    public static function outputMeters(): array
    {
        return [
            [['meter_summary'=>'生成秒数，总点数向上取整'], 'output_second', 4.0, 10.0],
            [['meter_contract'=>['version'=>1, 'basis'=>'output', 'rounding'=>'ceil']], 'business_unit', 4.0, 10.0],
            [['meter_contract'=>['version'=>1, 'basis'=>'call', 'rounding'=>'ceil']], 'output_second', 1.0, 3.0],
        ];
    }

    /** @dataProvider rejectedRequests */
    public function testNativeParameterAndMaterialConstraintsStillReject(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        Videos::prepareRequest($this->snapshot(), array_replace($this->request(), $changes));
    }

    public static function rejectedRequests(): array
    {
        return [[['prompt' => '']], [['duration' => 9]], [['quality' => '4K']], [['material_mode' => 'unknown']],
            [['reference_images' => []]], [['reference_images' => ['https://fixture.invalid/a.png', 'https://fixture.invalid/b.png']]]];
    }

    public function testApplicationMediaSlotsAreMeteredAndAssetUploadReplacementPreservesTheContext(): void
    {
        $snapshot = ['resource_type' => 'app_api', 'app_code' => 'happy_horse', 'locked_params' => ['model' => 'happyhorse-1.1-r2v'],
            'billing_meter' => MarketGenerationMeter::contract(['usage_unit' => 'per_call', 'meter_summary' => '生成张数 + 超过1的参考图片张数×1/2，总点数向上取整'])];
        $request = ['prompt' => 'fixture', 'duration' => 4, 'resolution' => '720p', 'generation_method' => 'image_reference',
            'reference_images' => ['https://fixture.invalid/a.png', 'https://fixture.invalid/b.png']];
        $prepared = Videos::prepareRequest($snapshot, $request, 'fixture-key');
        self::assertSame(2, $prepared->meterContext['reference_images']);
        self::assertSame('fixture-key', $prepared->nativeInput['idempotency_key']);
        $uploaded = $prepared->withApplicationInput(['content' => [['type' => 'image_url', 'image_url' => ['url' => 'asset://uploaded']]]]);
        self::assertSame($prepared->meterContext, $uploaded->meterContext);
        self::assertSame($prepared->billingMeter, $uploaded->billingMeter);
    }

    public function testSupplierUploadsRetainThePreparedPromptOrdinals(): void
    {
        $snapshot = ['resource_type' => 'app_api', 'app_code' => 'seedance', 'locked_params' => []];
        $request = ['prompt' => '@图 moves', 'duration' => 4, 'resolution' => '720p',
            'reference_assets' => [['type' => 'image', 'name' => '图', 'url' => 'https://fixture.invalid/frame.png']]];
        $prepared = Videos::prepareRequest($snapshot, $request, 'fixture-key');
        self::assertSame('@图片1 moves', $prepared->requestInput['prompt']);
        self::assertSame('@图 moves', $request['prompt'], 'UI/audit input must remain intact');
        $uploaded = $prepared->requestInput;
        $uploaded['reference_assets'] = [['type' => 'image', 'url' => 'asset://frame', 'uri' => 'asset://frame']];
        $payload = (new ReflectionMethod(Videos::class, 'appPayload'))->invoke(null, $snapshot, $uploaded, 'fixture-key');
        $locked = $prepared->withApplicationInput($payload);
        self::assertSame($prepared->nativeInput['content'][0]['text'], $locked->nativeInput['content'][0]['text']);
        self::assertSame('asset://frame', $locked->nativeInput['content'][1]['image_url']['url']);
    }
}
