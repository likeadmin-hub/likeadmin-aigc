<?php

namespace Tests\Feature;

use app\common\service\power\MarketImageModelRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketImageModelPayloadContractTest extends TestCase
{
    public function testQwenImageModelUsesDocumentedInputAndParametersPayload(): void
    {
        $payload = $this->invokePayload([
            'product_id' => 102,
            'sku_id' => 7001,
            'sku_key' => 'qwen_image_3_base_2k',
            'model_code' => 'qwen-image-3.0',
            'channel_code' => 'dashscope_compatible',
            'market_metadata' => [
                'params_schema' => [
                    'input' => ['type' => 'object', 'required' => true],
                    'parameters' => ['type' => 'object', 'required' => true],
                ],
                'max_reference_images' => 3,
            ],
            'pricing_attributes' => [[
                'code' => 'size',
                'param_path' => 'parameters.size',
                'options' => ['512*512', '1024*1024', '1024*2048'],
            ]],
            'locked_params' => ['_pricing_variant' => 'qwen_image_2k'],
        ], [
            'prompt' => '生成一个狗',
            'negative_prompt' => '低质量',
            'ratio' => '1:2',
            'quantity' => 4,
            'reference_images' => ['https://example.test/ref.png'],
        ]);

        self::assertSame('qwen-image-3.0', $payload['model']);
        self::assertSame('dashscope_compatible', $payload['channel']);
        self::assertArrayHasKey('input', $payload);
        self::assertArrayHasKey('parameters', $payload);
        self::assertSame([
            ['image' => 'https://example.test/ref.png'],
            ['text' => '生成一个狗'],
        ], $payload['input']['messages'][0]['content']);
        self::assertSame(1, $payload['parameters']['n']);
        self::assertSame('1024*2048', $payload['parameters']['size']);
        self::assertArrayNotHasKey('sku_key', $payload);
        self::assertSame('低质量', $payload['parameters']['negative_prompt']);
        self::assertArrayNotHasKey('_pricing_variant', $payload);
        self::assertArrayNotHasKey('_pricing_variant', $payload['parameters']);
        self::assertArrayNotHasKey('prompt', $payload);
        self::assertArrayNotHasKey('resolution', $payload);
    }

    public function testStructuredImagePayloadUsesTheSkuQualityWhenRatioIsOmitted(): void
    {
        $payload = $this->invokePayload([
            'product_id' => 102,
            'sku_id' => 7005,
            'sku_key' => 'qwen_image_3_base_2k',
            'model_code' => 'qwen-image-3.0',
            'channel_code' => 'dashscope_compatible',
            'market_metadata' => [
                'params_schema' => [
                    'input' => ['type' => 'object', 'required' => true],
                    'parameters' => ['type' => 'object', 'required' => true],
                ],
            ],
            'pricing_attributes' => [[
                'param_path' => 'parameters.size',
                'options' => ['512*512', '1024*1024', '1536*1024', '1024*1536', '2048*1024', '1024*2048'],
            ]],
            'locked_params' => ['_pricing_variant' => 'qwen_image_2k'],
        ], [
            'prompt' => 'generate a red square',
            'reference_images' => [],
        ]);

        self::assertSame('2048*1024', $payload['parameters']['size']);
        self::assertArrayNotHasKey('sku_key', $payload);
    }

    public function testFlatImageModelsFollowSyncedParameterSchema(): void
    {
        $gptPayload = $this->invokePayload([
            'product_id' => 96,
            'sku_id' => 7002,
            'sku_key' => 'gpt-image-2_resolution_2k',
            'model_code' => 'gpt-image-2',
            'channel_code' => 'OpenAIYI',
            'market_metadata' => [
                'params_schema' => [
                    'prompt' => ['type' => 'string', 'required' => true],
                    'image_urls' => ['type' => 'array'],
                    'resolution' => ['type' => 'string'],
                    'aspect_ratio' => ['type' => 'string'],
                ],
                'default_params' => [
                    'task_query' => ['image_results_top_level' => '1'],
                ],
            ],
            'locked_params' => ['resolution' => '2k'],
        ], [
            'prompt' => '生成产品海报',
            'ratio' => '16:9',
            'reference_images' => ['https://example.test/ref.png'],
        ]);

        self::assertSame('gpt-image-2', $gptPayload['model']);
        self::assertSame('2k', $gptPayload['resolution']);
        self::assertSame('16:9', $gptPayload['aspect_ratio']);
        self::assertSame(['https://example.test/ref.png'], $gptPayload['image_urls']);
        self::assertArrayNotHasKey('image_size', $gptPayload);
        self::assertArrayNotHasKey('task_query', $gptPayload);

        $fastPayload = $this->invokePayload([
            'product_id' => 97,
            'sku_id' => 7004,
            'sku_key' => 'gpt-image-2-fast_default_1k',
            'model_code' => 'gpt-image-2-fast',
            'channel_code' => 'openaiD',
            'market_metadata' => [
                'params_schema' => [
                    'prompt' => ['type' => 'string', 'required' => true],
                    'quality' => ['type' => 'string', 'options' => 'low / medium / high'],
                    'image_size' => ['type' => 'string'],
                    'image_urls' => ['type' => 'array'],
                    'aspect_ratio' => ['type' => 'string'],
                ],
                'default_params' => [
                    'image_size' => '1k',
                    'aspect_ratio' => 'auto',
                    'task_query' => ['image_results_top_level' => '1'],
                ],
            ],
            'locked_params' => [],
        ], [
            'prompt' => 'generate a poster',
        ]);

        self::assertSame('1k', $fastPayload['image_size']);
        self::assertArrayNotHasKey('quality', $fastPayload);
        self::assertArrayNotHasKey('task_query', $fastPayload);

        $fastHighQualityPayload = $this->invokePayload([
            'product_id' => 97,
            'sku_id' => 7004,
            'sku_key' => 'gpt-image-2-fast_default_1k',
            'model_code' => 'gpt-image-2-fast',
            'channel_code' => 'openaiD',
            'market_metadata' => [
                'params_schema' => [
                    'prompt' => ['type' => 'string', 'required' => true],
                    'quality' => ['type' => 'string', 'options' => 'low / medium / high'],
                    'image_size' => ['type' => 'string'],
                ],
                'default_params' => ['image_size' => '1k'],
            ],
            'locked_params' => ['resolution' => '1k'],
        ], [
            'prompt' => 'generate a poster',
            'provider_params' => ['quality' => 'high'],
        ]);

        self::assertSame('1k', $fastHighQualityPayload['image_size']);
        self::assertSame('high', $fastHighQualityPayload['quality']);

        $nanoPayload = $this->invokePayload([
            'product_id' => 66,
            'sku_id' => 7003,
            'sku_key' => 'nano-banana-pro_legacy_fixed',
            'model_code' => 'nano-banana-pro',
            'channel_code' => 'Google',
            'market_metadata' => [
                'params_schema' => [
                    'urls' => ['type' => 'array'],
                    'prompt' => ['type' => 'string'],
                    'image_size' => ['type' => 'string'],
                    'aspect_ratio' => ['type' => 'string'],
                ],
            ],
            'locked_params' => [],
        ], [
            'prompt' => '生成电商图',
            'quality' => '2K',
            'ratio' => '1:1',
            'reference_images' => ['https://example.test/product.png'],
        ]);

        self::assertSame('2K', $nanoPayload['image_size']);
        self::assertSame('1:1', $nanoPayload['aspect_ratio']);
        self::assertSame(['https://example.test/product.png'], $nanoPayload['urls']);
        self::assertArrayNotHasKey('resolution', $nanoPayload);
        self::assertArrayNotHasKey('image_urls', $nanoPayload);
    }

    public function testQwenSkuQualityCanBeDerivedFromPricingVariant(): void
    {
        $method = new ReflectionMethod(MarketImageModelRuntimeService::class, 'skuQuality');
        $method->setAccessible(true);

        self::assertSame('2k', $method->invoke(null, ['sku_key' => 'qwen_image_3_base_2k'], [
            '_pricing_variant' => 'qwen_image_2k',
        ]));
    }

    public function testImageModelResultParserReadsStructuredTaskResponses(): void
    {
        $taskId = new ReflectionMethod(MarketImageModelRuntimeService::class, 'taskId');
        $taskId->setAccessible(true);
        $status = new ReflectionMethod(MarketImageModelRuntimeService::class, 'status');
        $status->setAccessible(true);
        $collector = new ReflectionMethod(MarketImageModelRuntimeService::class, 'collectImageUrls');
        $collector->setAccessible(true);
        $error = new ReflectionMethod(MarketImageModelRuntimeService::class, 'error');
        $error->setAccessible(true);

        self::assertSame('task_123', $taskId->invoke(null, [
            'output' => ['task_id' => 'task_123'],
        ]));
        self::assertSame('completed', $status->invoke(null, [
            'output' => ['task_status' => 'COMPLETED'],
        ]));

        $urls = [];
        $collector->invokeArgs(null, [[
            'choices' => [[
                'message' => [
                    'content' => [
                        ['image' => 'https://example.test/result.png'],
                    ],
                ],
            ]],
        ], &$urls]);

        self::assertSame(['https://example.test/result.png'], $urls);
        self::assertSame('AccessDenied：上游暂时无法处理该模型，请稍后重试或切换模型', $error->invoke(null, [
            'error' => [
                'code' => 'AccessDenied',
                'message' => 'provider rejected the request',
            ],
        ]));
        self::assertSame('InvalidParameter：parameters.size must be one of the documented values', $error->invoke(null, [
            'output' => [
                'error_code' => 'InvalidParameter',
                'message' => 'parameters.size must be one of the documented values',
            ],
        ]));
    }

    public function testFailedImageResponseExposesTaskCardErrorFields(): void
    {
        $response = new ReflectionMethod(MarketImageModelRuntimeService::class, 'responseFromConsumption');
        $response->setAccessible(true);
        $message = 'AccessDenied：上游暂时无法处理该模型，请稍后重试或切换模型';

        $result = $response->invoke(null, [
            'run_status' => 'failed',
            'upstream_task_id' => 'task_123',
            'upstream_request_id' => 'request_123',
            'response_summary' => [],
            'error_message' => $message,
        ]);

        self::assertSame($message, $result['error']);
        self::assertSame($message, $result['error_msg']);
        self::assertSame($message, $result['errorDetails']);
    }

    private function invokePayload(array $snapshot, array $request): array
    {
        $method = new ReflectionMethod(MarketImageModelRuntimeService::class, 'payload');
        $method->setAccessible(true);
        return $method->invoke(null, $snapshot, $request, 'idem-1', 0);
    }
}
