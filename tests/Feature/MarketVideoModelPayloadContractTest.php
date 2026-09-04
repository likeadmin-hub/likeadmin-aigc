<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketVideoModelPayloadContractTest extends TestCase
{
    public function testWanVideoModelUsesDocumentedInputAndParametersPayload(): void
    {
        $payload = $this->invokeModelPayload([
            'product_id' => 101,
            'sku_id' => 8001,
            'sku_key' => 'wan_3_720p_output_second',
            'model_code' => 'wan3.0-video',
            'channel_code' => 'dashscope_compatible',
            'params_schema' => [
                'input' => ['type' => 'object', 'required' => true],
                'parameters' => ['type' => 'object', 'required' => true],
            ],
            'locked_params' => ['resolution' => '720P'],
        ], [
            'prompt' => '让图1中的角色自然行走',
            'ratio' => '9:16',
            'duration' => 5,
            'audio' => true,
            'watermark' => false,
            'reference_assets' => [
                ['type' => 'image', 'url' => 'https://example.test/character.png', 'role' => 'reference_image'],
                ['type' => 'video', 'url' => 'https://example.test/motion.mp4', 'role' => 'reference_video'],
                ['type' => 'audio', 'url' => 'https://example.test/voice.mp3', 'role' => 'reference_audio'],
            ],
        ]);

        self::assertSame('wan3.0-video', $payload['model']);
        self::assertSame('dashscope_compatible', $payload['channel']);
        self::assertSame('让图1中的角色自然行走', $payload['input']['prompt']);
        self::assertSame([
            ['type' => 'reference_image', 'url' => 'https://example.test/character.png'],
            ['type' => 'reference_video', 'url' => 'https://example.test/motion.mp4'],
            ['type' => 'reference_audio', 'url' => 'https://example.test/voice.mp3'],
        ], $payload['input']['media']);
        self::assertSame('720P', $payload['parameters']['resolution']);
        self::assertSame('9:16', $payload['parameters']['ratio']);
        self::assertSame(5, $payload['parameters']['duration']);
        self::assertTrue($payload['parameters']['audio']);
        self::assertFalse($payload['parameters']['watermark']);
        self::assertArrayNotHasKey('prompt', $payload);
        self::assertArrayNotHasKey('image_urls', $payload);
        self::assertArrayNotHasKey('quality', $payload);
    }

    public function testWanThreeExposesItsNativeTwoToThirtySecondRange(): void
    {
        $metadata = [
            'params_schema' => [
                'input' => ['type' => 'object', 'required' => true],
                'parameters' => [
                    'type' => 'object',
                    'required' => true,
                    'example' => ['resolution' => '720P', 'duration' => '5'],
                ],
            ],
        ];

        self::assertSame(range(2, 30), $this->invokePrivate(
            'durationOptionsForProduct',
            ['upstream_model_code' => 'wan3.0-video'],
            $metadata
        ));
        self::assertSame(['default' => 5], $this->invokePrivate('durationSchema', $metadata));
        self::assertSame([5], $this->invokePrivate(
            'durationOptionsForProduct',
            ['upstream_model_code' => 'another-video-model'],
            ['duration_options' => [5]]
        ));
    }

    public function testWanThreeAndFullVideoUseProtocolRatioFallbackOnlyWithoutCatalogContract(): void
    {
        self::assertSame(['adaptive', '16:9', '4:3', '1:1', '3:4', '9:16'], $this->invokePrivate(
            'ratioOptionsForProduct',
            ['upstream_model_code' => 'wan3.0-video'],
            [['locked_params' => ['resolution' => '720P'], 'selectable_params' => []]],
            ['params_schema' => ['parameters' => ['example' => ['ratio' => '16:9']]]]
        ));
        self::assertSame(['adaptive', '21:9', '16:9', '4:3', '1:1', '3:4', '9:16'], $this->invokePrivate(
            'ratioOptionsForProduct',
            ['upstream_app_code' => 'full_video'],
            [['locked_params' => ['resolution' => '480P'], 'selectable_params' => []]],
            ['default_params' => ['ratio' => '16:9']]
        ));
        self::assertSame(['1:1'], $this->invokePrivate(
            'ratioOptionsForProduct',
            ['upstream_model_code' => 'wan3.0-video'],
            [['locked_params' => ['resolution' => '720P'], 'selectable_params' => ['ratio_options' => ['1:1']]]],
            []
        ));
    }

    public function testFullVideoForwardsEveryConcreteH3AspectRatio(): void
    {
        $snapshot = [
            'product_id' => 183,
            'sku_id' => 698,
            'sku_key' => 'full_video_768p_per_second',
            'app_code' => 'full_video',
            'model_code' => 'full-video',
            'locked_params' => ['resolution' => '768P'],
            'requires_concrete_text_to_video_ratio' => true,
            'default_text_to_video_ratio' => '16:9',
        ];

        foreach (['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'] as $ratio) {
            $payload = $this->invokePrivate('appPayload', $snapshot, [
                'prompt' => '一艘木船驶过晨雾湖面',
                'ratio' => $ratio,
            ], 'full-video-ratio-test');
            self::assertSame($ratio, $payload['ratio']);
        }
    }

    public function testWanThreeAcceptsAndSubmitsThirtySecondDuration(): void
    {
        $metadata = [
            'params_schema' => [
                'input' => ['type' => 'object', 'required' => true],
                'parameters' => ['type' => 'object', 'required' => true, 'example' => ['duration' => '5']],
            ],
        ];
        $market = [
            'product' => [
                'resource_type' => 'model',
                'model_type' => 'video',
                'upstream_model_code' => 'wan3.0-video',
                'upstream_app_code' => '',
                'source_payload' => ['market_metadata' => $metadata],
            ],
            'sku' => [
                'locked_params' => ['resolution' => '720P'],
                'selectable_params' => [],
                'usage_unit' => 'output_second',
            ],
        ];

        $this->invokePrivate('assertSkuMatchesSelection', $market, [
            'quality' => '720P',
            'duration' => 30,
        ]);
        self::assertSame(30, $this->invokePrivate('effectiveDurationFromMarket', $market, 30));
        self::assertSame(30.0, $this->invokePrivate('quantity', $market, ['duration' => 30]));
        $payload = $this->invokeModelPayload([
            'product_id' => 101,
            'sku_id' => 8001,
            'sku_key' => 'wan_3_720p_output_second',
            'model_code' => 'wan3.0-video',
            'channel_code' => 'dashscope_compatible',
            'params_schema' => $metadata['params_schema'],
            'locked_params' => ['resolution' => '720P'],
        ], [
            'prompt' => '三十秒连续叙事镜头',
            'ratio' => '16:9',
            'duration' => 30,
        ]);

        self::assertSame(30, $payload['parameters']['duration']);
    }

    public function testWanThreeRejectsDurationAboveThirtySeconds(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('当前视频模型不支持所选时长');

        $this->invokePrivate('assertSkuMatchesSelection', [
            'product' => [
                'resource_type' => 'model',
                'model_type' => 'video',
                'upstream_model_code' => 'wan3.0-video',
                'upstream_app_code' => '',
                'source_payload' => ['market_metadata' => []],
            ],
            'sku' => [
                'locked_params' => ['resolution' => '720P'],
                'selectable_params' => [],
            ],
        ], [
            'quality' => '720P',
            'duration' => 31,
        ]);
    }

    public function testFlatVideoModelsFollowSyncedParameterSchema(): void
    {
        $payload = $this->invokeModelPayload([
            'product_id' => 103,
            'sku_id' => 8002,
            'sku_key' => 'veo3_1_fast_720p',
            'model_code' => 'veo3.1-fast',
            'channel_code' => 'xAIQ',
            'params_schema' => [
                'prompt' => ['type' => 'string', 'required' => true],
                'quality' => ['type' => 'string'],
                'duration' => ['type' => 'integer'],
                'image_urls' => ['type' => 'array'],
                'aspect_ratio' => ['type' => 'string'],
                'generation_type' => ['type' => 'string'],
            ],
            'locked_params' => ['quality' => '720p'],
        ], [
            'prompt' => 'The car moves forward',
            'ratio' => '16:9',
            'duration' => 8,
            'generation_type' => 'REFERENCE',
            'reference_assets' => [
                ['type' => 'image', 'url' => 'https://example.test/ref.jpg'],
            ],
        ]);

        self::assertSame('veo3.1-fast', $payload['model']);
        self::assertSame('720p', $payload['quality']);
        self::assertSame(8, $payload['duration']);
        self::assertSame('16:9', $payload['aspect_ratio']);
        self::assertSame('REFERENCE', $payload['generation_type']);
        self::assertSame(['https://example.test/ref.jpg'], $payload['image_urls']);
        self::assertArrayNotHasKey('n', $payload);
        self::assertArrayNotHasKey('negative_prompt', $payload);
        self::assertArrayNotHasKey('video_urls', $payload);
        self::assertArrayNotHasKey('audio_urls', $payload);
    }

    public function testVideoSkuLockedResolutionWinsOverRequestResolution(): void
    {
        $payload = $this->invokeModelPayload([
            'product_id' => 101,
            'sku_id' => 8001,
            'sku_key' => 'wan_3_480p_output_second',
            'model_code' => 'wan3.0-video',
            'channel_code' => 'dashscope_compatible',
            'params_schema' => [
                'input' => ['type' => 'object', 'required' => true],
                'parameters' => ['type' => 'object', 'required' => true],
            ],
            'locked_params' => ['resolution' => '480P'],
        ], [
            'prompt' => 'The square moves',
            'ratio' => '16:9',
            'duration' => 2,
            'quality' => '720p',
            'resolution' => '720p',
        ]);

        self::assertSame('480P', $payload['parameters']['resolution']);
        self::assertSame(2, $payload['parameters']['duration']);
    }

    public function testVeoFixedDurationFromSyncedSchemaIsAcceptedByPayload(): void
    {
        $payload = $this->invokeModelPayload([
            'product_id' => 103,
            'sku_id' => 8002,
            'sku_key' => 'veo3_1_fast_720p',
            'model_code' => 'veo3.1-fast',
            'channel_code' => 'xAIQ',
            'params_schema' => [
                'prompt' => ['type' => 'string', 'required' => true],
                'quality' => ['type' => 'string'],
                'duration' => ['type' => 'integer', 'default' => 8, 'options' => '8'],
                'image_urls' => ['type' => 'array'],
                'aspect_ratio' => ['type' => 'string'],
                'generation_type' => ['type' => 'string'],
            ],
            'locked_params' => ['quality' => '720p'],
        ], [
            'prompt' => 'The square moves',
            'ratio' => '16:9',
            'duration' => 8,
            'generation_type' => 'TEXT',
        ]);

        self::assertSame('720p', $payload['quality']);
        self::assertSame(8, $payload['duration']);
        self::assertSame('TEXT', $payload['generation_type']);
    }

    public function testFlatVideoPayloadUsesSchemaDefaultsAndLockedRatio(): void
    {
        $payload = $this->invokeModelPayload([
            'product_id' => 103,
            'sku_id' => 8002,
            'sku_key' => 'veo3_1_fast_720p',
            'model_code' => 'veo3.1-fast',
            'channel_code' => 'xAIQ',
            'params_schema' => [
                'prompt' => ['type' => 'string', 'required' => true],
                'quality' => ['type' => 'string'],
                'duration' => ['type' => 'integer', 'default' => 8, 'options' => '8'],
                'image_urls' => ['type' => 'array'],
                'aspect_ratio' => ['type' => 'string', 'default' => '16:9', 'options' => '16:9 / 9:16'],
                'generation_type' => ['type' => 'string', 'default' => 'TEXT', 'options' => 'TEXT / FIRST&LAST / REFERENCE'],
            ],
            'locked_params' => ['quality' => '720p', 'aspect_ratio' => '9:16'],
        ], [
            'prompt' => 'The square moves',
            'ratio' => '16:9',
        ]);

        self::assertSame(8, $payload['duration']);
        self::assertSame('9:16', $payload['aspect_ratio']);
        self::assertSame('TEXT', $payload['generation_type']);
    }

    public function testVideoModelErrorsAndFailedResponsesAreUserVisible(): void
    {
        $error = new ReflectionMethod(MarketVideoRuntimeService::class, 'error');
        $error->setAccessible(true);
        $message = 'AccessDenied：上游暂时无法处理该模型，请稍后重试或切换模型';

        self::assertSame($message, $error->invoke(null, [
            'output' => ['code' => 'AccessDenied', 'message' => 'channel rejected this model'],
        ]));
        self::assertSame('InvalidParameter：duration must be one of 5, 8', $error->invoke(null, [
            'error' => ['code' => 'InvalidParameter', 'message' => 'duration must be one of 5, 8'],
        ]));

        $response = new ReflectionMethod(MarketVideoRuntimeService::class, 'response');
        $response->setAccessible(true);
        $result = $response->invoke(null, [
            'run_status' => 'failed',
            'upstream_task_id' => 'video_123',
            'upstream_request_id' => 'request_123',
            'response_summary' => [],
            'error_message' => $message,
        ]);

        self::assertSame($message, $result['error']);
        self::assertSame($message, $result['error_msg']);
        self::assertSame($message, $result['errorDetails']);
    }

    private function invokeModelPayload(array $snapshot, array $request): array
    {
        $method = new ReflectionMethod(MarketVideoRuntimeService::class, 'modelPayload');
        $method->setAccessible(true);
        return $method->invoke(null, $snapshot, $request, 'idem-video-1');
    }

    private function invokePrivate(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MarketVideoRuntimeService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
