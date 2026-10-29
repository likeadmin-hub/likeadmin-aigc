<?php

namespace Tests\Feature;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\CanvasGenerationParameters;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use app\common\service\power\MarketCreationParameters;
use app\common\service\power\MarketGenerationControls;
use app\common\service\power\MarketGenerationMeter;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Pure preparation only: synthetic declarations never submit to a provider. */
class MarketImagePreparationTest extends TestCase
{
    private function snapshot(): array
    {
        return ['model_code' => 'new-declared-image', 'channel_code' => 'fixture', 'sku_key' => 'fixture-fixed',
            'locked_params' => [], 'billing_meter' => null, 'market_metadata' => [
                'max_reference_images' => 2,
                'params_schema' => [
                    'prompt' => ['type' => 'string', 'required' => '1', 'maxLength' => 100],
                    'image_size' => ['type' => 'string', 'options' => '1K / 2K / 4K'],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '16:9']],
                    'image_urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
                    'seed' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'watermark' => ['type' => 'boolean'],
                ],
            ]];
    }

    private function request(): array
    {
        return ['channel' => 'fixture', 'prompt' => 'Offline fixture', 'ratio' => '1:1', 'quality' => '4k',
            'quantity' => 1, 'reference_images' => ['https://fixture.invalid/ref.png'],
            'advanced_params' => ['seed' => 0, 'watermark' => false]];
    }

    private function market(): array
    {
        $s = $this->snapshot();
        return ['product' => ['id' => 990001, 'resource_type' => 'model', 'upstream_model_code' => $s['model_code'],
            'upstream_channel_code' => $s['channel_code'], 'source_payload' => ['market_metadata' => $s['market_metadata']]],
            'sku' => ['id' => 990002, 'sku_key' => $s['sku_key'], 'locked_params' => [], 'usage_unit' => 'per_call',
                'usage_unit_size' => 1, 'upstream_price' => 3, 'sale_points' => 3],
            'tenant_price' => 3, 'reference_limit' => 2];
    }

    public function testNewModelDeclarationReusesStandaloneCanvasAndShortDramaInput(): void
    {
        $s = $this->snapshot();
        $model = ['value' => 'fixture', 'resource_type' => 'model', 'params_schema' => $s['market_metadata']['params_schema']];
        $input = $this->request();
        $configured = MarketCreationParameters::applyOptions($input, [$model], 'image');
        // The real canvas applies advanced controls before normalization, then
        // carries provider_params into both pricing and generateMarketImage.
        $canvas = array_replace($configured,
            (new ReflectionMethod(AigcCanvasService::class, 'normalizeImageParams'))->invoke(null, $configured, 0, 0, false));
        $drama = (new ReflectionMethod(ShortDramaCanvasService::class, 'generationPayload'))->invoke(null, 'image', $input, 0, 0, 1, false);
        $expected = null;
        foreach ([$input, $canvas, $drama] as $request) {
            $request = MarketCreationParameters::applyOptions($request, [$model], 'image');
            $prepared = Images::prepareRequest($s, $request);
            $expected ??= $prepared->nativeInput;
            self::assertSame($expected, $prepared->nativeInput);
            self::assertSame('4K', $prepared->nativeInput['image_size']);
            self::assertSame(0, $prepared->nativeInput['seed']);
            self::assertFalse($prepared->nativeInput['watermark']);
            self::assertArrayNotHasKey('idempotency_key', $prepared->nativeInput);
        }
        self::assertSame(MarketGenerationControls::definitions($model, 'image'), CanvasGenerationParameters::definitions($model, 'image'));
    }

    public function testFixedPriceQuotePreparesAndValidatesTheNativeInput(): void
    {
        $method = new ReflectionMethod(Images::class, 'meterMarket');
        $market = $method->invoke(null, $this->market(), $this->request());
        self::assertArrayNotHasKey('billing_meter', $market, 'Legacy fixed-price quantity semantics must remain unchanged');
        $this->expectException(InvalidArgumentException::class);
        $method->invoke(null, $this->market(), array_replace($this->request(), ['quality' => '8k']));
    }

    public function testMeterContextUsesPreciselyThePreparedSubmission(): void
    {
        $market = $this->market();
        $market['sku']['source_payload'] = ['meter_summary' => '生成张数 + 超过1的参考图片张数×1/2，总点数向上取整'];
        $request = $this->request();
        $request['reference_images'][] = 'https://fixture.invalid/second.png';
        $quoted = (new ReflectionMethod(Images::class, 'meterMarket'))->invoke(null, $market, $request);
        $snapshot = (new ReflectionMethod(Images::class, 'snapshot'))->invoke(null, $quoted);
        $submitted = Images::prepareRequest($snapshot, $request);
        self::assertSame($quoted['meter_context'], $submitted->meterContext);
        self::assertSame(2, $submitted->meterContext['reference_images']);
        self::assertSame(5.0, MarketGenerationMeter::price(3, 1, $snapshot));
        self::assertSame('4K', $submitted->nativeInput['image_size']);
    }

    /** @dataProvider invalidInput */
    public function testDeclaredConstraintsRemainEnforced(array $changes): void
    {
        $this->expectException(\Exception::class);
        Images::prepareRequest($this->snapshot(), array_replace($this->request(), $changes));
    }

    public static function invalidInput(): array
    {
        return [
            'required prompt' => [['prompt' => '']],
            'resolution enum' => [['quality' => '8k']],
            'ratio enum' => [['ratio' => '2:7']],
            'reference limit' => [['reference_images' => array_fill(0, 3, 'https://fixture.invalid/a.png')]],
            'parameter range' => [['provider_params' => ['seed' => -1]]],
            'boolean type' => [['provider_params' => ['watermark' => 'invalid']]],
        ];
    }

    public function testOnlyAbsentPromptCanBeDeferredInPreliminaryQuotes(): void
    {
        $request = $this->request(); unset($request['prompt']);
        $prepared = Images::prepareRequest($this->snapshot(), $request, 0, true);
        self::assertArrayNotHasKey('prompt', $prepared->nativeInput);
        $this->expectException(InvalidArgumentException::class);
        Images::prepareRequest($this->snapshot(), $request + ['prompt' => ''], 0, true);
    }

    public function testSkuLocksAndOpaqueStructuredInputRemainIntact(): void
    {
        $snapshot = $this->snapshot(); $snapshot['locked_params'] = ['image_size' => '2K'];
        self::assertSame('2K', Images::prepareRequest($snapshot, $this->request())->nativeInput['image_size']);
        $snapshot = ['model_code' => 'qwen-image-3.0', 'channel_code' => 'fixture', 'market_metadata' => [
            'max_reference_images' => 2, 'params_schema' => ['input' => ['type' => 'object', 'required' => '1', 'example' => ['messages' => []]],
                'parameters' => ['type' => 'object', 'required' => '1', 'example' => ['n' => 1]]]]];
        $native = Images::prepareRequest($snapshot, array_replace($this->request(), ['quality' => '1k']))->nativeInput;
        self::assertSame('Offline fixture', $native['input']['messages'][0]['content'][1]['text']);
        self::assertSame(1, $native['parameters']['n']);
    }
}
