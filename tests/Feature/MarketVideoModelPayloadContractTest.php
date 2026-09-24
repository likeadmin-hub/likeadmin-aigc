<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketVideoModelPayloadContractTest extends TestCase
{
    public function testIdenticalFrameImageRetainsBothRolesInProviderPayload(): void
    {
        $uri = 'https://fixtures.invalid/shared.png';
        $payload = $this->invokeModelPayload(['model_code'=>'wan3.0-video','channel_code'=>'isolated'], [
            'prompt'=>'Synthetic frame test', 'generation_method'=>'start_end',
            'reference_assets'=>[
                ['type'=>'image','url'=>$uri,'role'=>'first_frame_image'],
                ['type'=>'image','url'=>$uri,'role'=>'last_frame_image'],
            ],
            'reference_images'=>[$uri],
        ]);
        self::assertSame([
            ['type'=>'first_frame','url'=>$uri], ['type'=>'last_frame','url'=>$uri],
        ], $payload['input']['media']);
        self::assertSame('idem-video-1', $payload['idempotency_key']);
    }

    public function testSwappingFrameAssignmentsChangesRolesWithoutLosingOrder(): void
    {
        $snapshot=['model_code'=>'wan3.0-video','channel_code'=>'isolated'];
        $request=['prompt'=>'Synthetic frame test','generation_method'=>'start_end','reference_assets'=>[
            ['type'=>'image','url'=>'https://fixtures.invalid/a.png','role'=>'first_frame_image'],
            ['type'=>'image','url'=>'https://fixtures.invalid/b.png','role'=>'last_frame_image'],
        ]];
        $before=$this->invokeModelPayload($snapshot,$request);
        $request['reference_assets'][0]['role']='last_frame_image';
        $request['reference_assets'][1]['role']='first_frame_image';
        $after=$this->invokeModelPayload($snapshot,$request);
        self::assertSame(['first_frame','last_frame'],array_column($before['input']['media'],'type'));
        self::assertSame(['last_frame','first_frame'],array_column($after['input']['media'],'type'));
        self::assertSame(array_column($before['input']['media'],'url'),array_column($after['input']['media'],'url'));
        self::assertNotSame($before['input']['media'],$after['input']['media']);
    }

    public function testSameUseUploadAndNodeReferenceReachProviderPayloadOnlyOnce(): void
    {
        $reference=['type'=>'image','url'=>'https://fixtures.invalid/one.png','role'=>'reference_image'];
        $payload=$this->invokeModelPayload(['model_code'=>'wan3.0-video','channel_code'=>'isolated'],[
            'prompt'=>'Synthetic reference test',
            'reference_assets'=>[$reference,$reference], 'reference_images'=>[$reference['url']],
        ]);
        self::assertSame([['type'=>'reference_image','url'=>$reference['url']]],$payload['input']['media']);
    }

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

    public function testWanCanvasReferencesAndTwoSecondAudioChoiceReachTheDocumentedFields(): void
    {
        $payload = $this->invokeModelPayload([
            'model_code' => 'wan3.0-video',
            'channel_code' => 'dashscope_compatible',
            'params_schema' => ['input' => ['type' => 'object'], 'parameters' => ['type' => 'object']],
            'locked_params' => ['resolution' => '480P'],
        ], [
            'prompt' => '@图片1 用@音频1 对@图片3说话',
            'duration' => 2,
            'generate_audio' => false,
            'reference_assets' => [
                ['type' => 'image', 'url' => 'https://example.test/hero.png', 'role' => 'reference_image'],
                ['type' => 'image', 'url' => 'https://example.test/setting.png', 'role' => 'reference_image'],
                ['type' => 'image', 'url' => 'https://example.test/partner.png', 'role' => 'reference_image'],
                ['type' => 'audio', 'url' => 'https://example.test/voice.mp3', 'role' => 'reference_audio'],
            ],
        ]);

        self::assertSame('图1 用音频1 对图3说话', $payload['input']['prompt']);
        self::assertSame(2, $payload['parameters']['duration']);
        self::assertFalse($payload['parameters']['audio']);
        self::assertSame(['reference_image', 'reference_image', 'reference_image', 'reference_audio'], array_column($payload['input']['media'], 'type'));
    }

    public function testAnotherStructuredModelDoesNotInheritWanAudioOrReferenceSyntax(): void
    {
        $payload = $this->invokeModelPayload([
            'model_code' => 'another-structured-video',
            'params_schema' => ['input' => ['type' => 'object'], 'parameters' => ['type' => 'object']],
        ], ['prompt' => '@图片1移动', 'duration' => 4, 'generate_audio' => false]);

        self::assertSame('@图片1移动', $payload['input']['prompt']);
        self::assertSame(4, $payload['parameters']['duration']);
        self::assertArrayNotHasKey('audio', $payload['parameters']);
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

    public function testH3PayloadUsesItsDocumentedTopLevelContractAndStringDurationEnum(): void
    {
        $payload = $this->invokeModelPayload([
            'product_id' => 98,
            'sku_id' => 119,
            'sku_key' => 'h3_768p_output_second',
            'model_code' => 'h3-video',
            'channel_code' => 'minimax',
            'locked_params' => ['resolution' => '768P'],
        ], [
            'prompt' => '基于全部参考图片生成自然镜头运动。',
            'ratio' => '9:16',
            'duration' => 5,
            'callback_url' => 'https://example.test/h3-callback',
            'reference_assets' => array_map(static fn(int $index): array => [
                'type' => 'image',
                'url' => 'https://example.test/reference-' . $index . '.png',
                'role' => 'reference_image',
            ], range(1, 7)),
        ]);

        self::assertSame(
            ['model', 'ratio', 'resolution', 'duration', 'content', 'callback_url'],
            array_keys($payload)
        );
        self::assertSame('h3-video', $payload['model']);
        self::assertSame('9:16', $payload['ratio']);
        self::assertSame('768P', $payload['resolution']);
        self::assertSame('5', $payload['duration']);
        self::assertCount(8, $payload['content']);
        self::assertSame('text', $payload['content'][0]['type']);
        self::assertSame(
            array_fill(0, 7, 'reference_image'),
            array_column(array_slice($payload['content'], 1), 'role')
        );
        foreach (['channel', 'market_product_id', 'market_sku_id', 'sku_id', 'sku_key', 'pricing_sku_key', 'price_source', 'idempotency_key'] as $field) {
            self::assertArrayNotHasKey($field, $payload);
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
