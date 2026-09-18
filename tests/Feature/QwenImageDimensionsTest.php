<?php

namespace Tests\Feature;

use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class QwenImageDimensionsTest extends TestCase
{
    private function snapshot(string $model, string $quality = '1k'): array
    {
        return [
            'model_code' => $model,
            'locked_params' => ['_pricing_variant' => 'qwen_image_' . $quality],
            'market_metadata' => ['params_schema' => [
                'input' => ['type' => 'object'],
                'parameters' => ['type' => 'object', 'example' => ['size' => '1024*1024']],
            ]],
            'pricing_attributes' => [['param_path' => 'parameters.size',
                'options' => ['512*512', '1024*1024', '1536*1024', '1024*1536', '2048*1024', '1024*2048']]],
        ];
    }

    public function testBothModelsMapRatioAndQualityWithoutUsingExampleOrWhitelist(): void
    {
        foreach (['qwen-image-3.0', 'qwen-image-3.0-pro'] as $model) {
            foreach (['1k' => ['1024*576', '576*1024', '1024*1024'], '2k' => ['2048*1152', '1152*2048', '2048*2048']] as $quality => $sizes) {
                foreach (['16:9', '9:16', '1:1'] as $index => $ratio) {
                    $snapshot = $this->snapshot($model, $quality);
                    $request = ['ratio' => $ratio, 'quality' => $quality, 'quantity' => 1];
                    $summary = Images::qwenDimensionSummary($snapshot, $request);
                    self::assertSame($sizes[$index], $summary['submitted_size']);
                    $method = new ReflectionMethod(Images::class, 'payload');
                    $method->setAccessible(true);
                    $payload = $method->invoke(null, $snapshot, $request, 'fixture', 0);
                    self::assertSame($summary['submitted_size'], $payload['parameters']['size']);
                    self::assertSame(1, $payload['parameters']['n']);
                    self::assertArrayNotHasKey('aspect_ratio', $payload);
                    self::assertArrayNotHasKey('resolution', $payload['parameters']);
                }
            }
        }
    }

    public function testBothModelsExposeTheCommonRatioPickerWhenTheSchemaOnlyHasSize(): void
    {
        $method = new ReflectionMethod(Images::class, 'ratioOptions');
        $method->setAccessible(true);
        $expected = ['1:1', '3:2', '2:3', '4:3', '3:4', '5:4', '4:5', '16:9', '9:16', '2:1', '1:2', '3:1', '1:3', '21:9', '9:21'];

        foreach (['qwen-image-3.0', 'qwen-image-3.0-pro'] as $model) {
            self::assertSame($expected, $method->invoke(null, ['_pricing_variant' => 'qwen_image_1k'], [
                'params_schema' => ['input' => ['type' => 'object'], 'parameters' => ['type' => 'object']],
            ], $model));
        }

        self::assertSame(['1:1'], $method->invoke(null, ['aspect_ratio' => '1:1'], [], 'qwen-image-3.0'));
    }

    public function testBoundaryRatiosAndIntegerRounding(): void
    {
        foreach (['1k', '2k'] as $quality) {
            foreach (['1:8', '8:1', '4:3', '3:4', '21:9', '9:21'] as $ratio) {
                $summary = Images::qwenDimensionSummary($this->snapshot('qwen-image-3.0', $quality), ['ratio' => $ratio]);
                [$w, $h] = array_map('intval', explode('*', $summary['submitted_size']));
                [$a, $b] = array_map('intval', explode(':', $ratio));
                self::assertGreaterThanOrEqual(512 * 512, $w * $h);
                self::assertLessThanOrEqual(2048 * 2048, $w * $h);
                self::assertGreaterThanOrEqual(0.125, $w / $h);
                self::assertLessThanOrEqual(8, $w / $h);
                self::assertLessThanOrEqual(1, min(abs($w - $h * $a / $b), abs($h - $w * $b / $a)));
            }
        }
    }

    public function testInvalidAndConflictingExplicitSizesAreRejected(): void
    {
        foreach ([
            ['ratio' => '16:9', 'size' => '1024*1024'],
            ['ratio' => '1:1', 'size' => '0*1024'],
            ['ratio' => '1:1', 'size' => '256*256'],
            ['ratio' => '1:1', 'size' => '4096*4096'],
            ['ratio' => '1:1', 'size' => 'invalid'],
            ['ratio' => '9:1'], ['ratio' => '1:9'], ['ratio' => '0:1'], ['ratio' => 'invalid'],
            ['ratio' => '16:9', 'provider_params' => ['parameters' => ['size' => '1024*1024']]],
        ] as $request) {
            try {
                Images::qwenDimensionSummary($this->snapshot('qwen-image-3.0'), $request);
                self::fail('Expected invalid size to be rejected: ' . json_encode($request));
            } catch (\Exception $e) {
                self::assertStringContainsString('Qwen Image', $e->getMessage());
            }
        }
    }

    public function testExplicitDimensionsAndHistoricalDefaults(): void
    {
        self::assertSame('1280*720', Images::qwenDimensionSummary($this->snapshot('qwen-image-3.0'), ['ratio' => '16:9', 'size' => '1280x720'])['submitted_size']);
        self::assertSame('2048*1024', Images::qwenDimensionSummary($this->snapshot('qwen-image-3.0', '2k'), [])['submitted_size']);
        self::assertSame([], Images::qwenDimensionSummary($this->snapshot('other-model'), ['ratio' => '16:9']));
        $method = new ReflectionMethod(Images::class, 'structuredSize');
        $method->setAccessible(true);
        $this->expectExceptionMessage('当前图片规格不支持 16:9');
        $method->invoke(null, $this->snapshot('other-model'), [], [], '16:9', '1k', '');
    }

    public function testShortDramaForwardsOnlyQwenDimensionOverrides(): void
    {
        $method = new ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'withQwenImageDimensions');
        $method->setAccessible(true);
        $base = ['prompt' => 'fixture', 'ratio' => '16:9', 'quality' => '1k'];
        $request = ['params' => ['size' => '1024*1024', 'resolution' => '1k'],
            'provider_params' => ['parameters' => ['size' => '1024*1024'], 'unrelated_option' => 'ignored']];
        self::assertSame($base, $method->invoke(null, $this->snapshot('other-model'), $base, $request));
        $mapped = $method->invoke(null, $this->snapshot('qwen-image-3.0'), $base, $request);
        self::assertSame('1024*1024', $mapped['size']);
        self::assertArrayNotHasKey('unrelated_option', $mapped['provider_params']);
        $this->expectExceptionMessage('图片尺寸与所选 16:9 比例不一致');
        Images::qwenDimensionSummary($this->snapshot('qwen-image-3.0'), $mapped);
    }

    public function testShortDramaUsesTheFrozenMarketReferenceLimit(): void
    {
        $snapshot = $this->snapshot('qwen-image-3.0-pro');
        $snapshot['market_metadata']['capabilities']['max_reference_images'] = 3;

        self::assertSame(3, Images::referenceImageLimitFromSnapshot($snapshot));

        $method = new ReflectionMethod(
            \app\common\service\app\aigc_short_drama\AigcShortDramaService::class,
            'imageReferenceImageLimit'
        );
        $method->setAccessible(true);
        self::assertSame(3, $method->invoke(null, 1, ['channel' => 'market_image_model:fixture'], $snapshot));
    }
}
