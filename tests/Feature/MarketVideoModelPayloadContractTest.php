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
}
