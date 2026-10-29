<?php

namespace Tests\Feature;

use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\CanvasGenerationParameters;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Uses the 2026-10-18 synchronized declaration fixture, not a live provider. */
class LegacyMarketCatalogueParityTest extends TestCase
{
    private const IMAGE = 'https://fixtures.invalid/reference.png';

    private static function contract(string $type, string $code, string $channel): array
    {
        $fixture = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/canvas-generation-market-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['contracts'] as $contract) {
            if (($contract['type'] ?? '') === $type && ($contract['resource_type'] ?? '') === 'model'
                && ($contract['model_code'] ?? '') === $code && ($contract['channel_code'] ?? '') === $channel) return $contract;
        }
        throw new \RuntimeException('Missing captured market declaration: ' . $code . '/' . $channel);
    }

    private static function call(string $class, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, $method))->invokeArgs(null, $args);
    }

    public static function imageModels(): array
    {
        return [
            ['nano-banana-2.1', 'Google', 'urls'], ['nano-banana-2', 'Google', 'urls'],
            ['nano-banana-pro', 'Google', 'urls'],
            ['gpt-image-2.5', 'OpenAIYI', 'image_urls'], ['gpt-image-2.5-flare', 'OpenAIYI', 'image_urls'],
            ['gpt-image-2.5-sunburst', 'OpenAIYI', 'image_urls'],
            ['gpt-image-2', 'OpenAIYI', 'image_urls'], ['gpt-image-2', 'OpenaiM', 'image_urls'],
            ['gpt-image-2-fast', 'openaiD', 'image_urls'], ['gpt-image-2-pro', 'OpenaiM', 'image_urls'],
            ['qwen-image-3.0', 'dashscope_compatible', 'input'], ['qwen-image-3.0-pro', 'dashscope_compatible', 'input'],
        ];
    }

    /** @dataProvider imageModels */
    public function testImageModelKeepsPromptAndReferenceFromCapturedDeclaration(string $code, string $channel, string $referenceField): void
    {
        $contract = self::contract('image', $code, $channel);
        $snapshot = ['model_code' => $code, 'channel_code' => $channel, 'locked_params' => [], 'market_metadata' => $contract];
        $request = ['prompt' => 'fixture prompt', 'ratio' => '1:1', 'quality' => '1k', 'reference_images' => [self::IMAGE]];
        $payload = self::call(MarketImageModelRuntimeService::class, 'payload', $snapshot, $request, 'fixture');
        if ($referenceField === 'input') {
            self::assertSame(self::IMAGE, $payload['input']['messages'][0]['content'][0]['image']);
            self::assertSame('fixture prompt', $payload['input']['messages'][0]['content'][1]['text']);
        } else {
            self::assertSame([self::IMAGE], $payload[$referenceField]);
            self::assertSame('fixture prompt', $payload['prompt']);
            self::assertSame('1:1', $payload['aspect_ratio']);
        }
        self::assertSame($code, $payload['model']);
        self::assertSame($channel, $payload['channel']);
        $request['reference_images'] = [];
        $textOnly = self::call(MarketImageModelRuntimeService::class, 'payload', $snapshot, $request, 'fixture');
        if ($referenceField === 'input') self::assertSame('fixture prompt', $textOnly['input']['messages'][0]['content'][0]['text']);
        else self::assertArrayNotHasKey($referenceField, $textOnly);
    }

    public static function videoModels(): array
    {
        return [
            ['q4-preview-video', 'q4_video', 'images'], ['h3-video', 'minimax', 'content'],
            ['grok-video', 'xAIQ', 'image_urls'], ['veo3.1-fast', 'xAIQ', 'image_urls'],
            ['veo3.1-pro', 'xAIQ', 'image_urls'], ['wan3.0-video', 'dashscope_compatible', 'input'],
        ];
    }

    /** @dataProvider videoModels */
    public function testVideoModelKeepsReferenceAndDurationFromCapturedDeclaration(string $code, string $channel, string $referenceField): void
    {
        $contract = self::contract('video', $code, $channel);
        $snapshot = ['model_code' => $code, 'channel_code' => $channel, 'params_schema' => $contract['params_schema'], 'market_metadata' => $contract, 'locked_params' => []];
        $duration = str_starts_with($code, 'veo') ? 8 : 5;
        $request = ['prompt' => 'fixture prompt', 'duration' => $duration, 'ratio' => '9:16',
            'reference_assets' => [['type' => 'image', 'role' => 'first_frame_image', 'url' => self::IMAGE]]];
        $payload = self::call(MarketVideoRuntimeService::class, 'modelPayload', $snapshot, $request, 'fixture');
        self::assertSame($code, $payload['model']);
        if ($referenceField === 'content') {
            self::assertSame(self::IMAGE, $payload['content'][1]['image_url']['url']);
            self::assertSame((string)$duration, $payload['duration']);
        } elseif ($referenceField === 'input') {
            self::assertSame(self::IMAGE, $payload['input']['media'][0]['url']);
            self::assertSame($duration, $payload['parameters']['duration']);
        } else {
            self::assertSame([self::IMAGE], $payload[$referenceField]);
            self::assertSame($duration, $payload['duration']);
        }
    }

    public static function nanoVariants(): array
    {
        return array_map(static fn($model) => [$model], [
            'nano-banana', 'nano-banana-2', 'nano-banana-2-lite', 'nano-banana-pro',
            'nano-banana:official', 'nano-banana-2:official', 'nano-banana-2-lite:official', 'nano-banana-pro:official',
        ]);
    }

    /** @dataProvider nanoVariants */
    public function testNanoApplicationPreservesVariantAndSelectsGenerateOrEdit(string $model): void
    {
        $snapshot = ['model_code' => $model, 'locked_params' => ['model' => $model]];
        $request = ['prompt' => 'fixture prompt', 'ratio' => '1:1', 'quality' => '1k', 'reference_images' => [self::IMAGE]];
        $edit = self::call(MarketNanoBananaAppRuntimeService::class, 'payload', $snapshot, $request, 'fixture');
        self::assertSame($model, $edit['model']);
        self::assertSame('edit', $edit['action']);
        self::assertSame([self::IMAGE], $edit['image_urls']);
        self::assertSame('1:1', $edit['aspect_ratio']);
        self::assertSame('1K', $edit['resolution']);
        $request['reference_images'] = [];
        $generate = self::call(MarketNanoBananaAppRuntimeService::class, 'payload', $snapshot, $request, 'fixture');
        self::assertSame('generate', $generate['action']);
        self::assertArrayNotHasKey('image_urls', $generate);
    }

    public function testNanoApplicationRejectsReferencesAboveSelectedModelLimit(): void
    {
        $this->expectExceptionMessage('最多支持 1 张参考图');
        self::call(MarketNanoBananaAppRuntimeService::class, 'assertReferences', ['reference_limit' => 1], [
            'reference_images' => [self::IMAGE, 'https://fixtures.invalid/other.png'],
        ]);
    }

    public static function videoApplications(): array
    {
        return [
            ['seedance', [], 'content'], ['wan', ['model' => 'wan2.7'], 'image_urls'],
            ['grok_video', ['model' => 'grok-imagine-video-1.5-fast'], 'image_urls'],
            ['happy_horse', [], 'media'], ['full_video', [], 'content'],
        ];
    }

    /** @dataProvider videoApplications */
    public function testVideoApplicationPreservesReferenceAndDuration(string $app, array $locked, string $field): void
    {
        $snapshot = ['app_code' => $app, 'model_code' => '', 'locked_params' => $locked];
        $request = ['prompt' => 'fixture prompt', 'duration' => 5, 'ratio' => '9:16', 'resolution' => '720p',
            'generation_method' => 'image_to_video', 'reference_assets' => [['type' => 'image', 'role' => 'first_frame_image', 'url' => self::IMAGE]]];
        $payload = self::call(MarketVideoRuntimeService::class, 'appPayload', $snapshot, $request, 'fixture');
        self::assertSame(5, $payload['duration']);
        if ($app === 'seedance' || $app === 'full_video') self::assertSame(self::IMAGE, $payload['content'][1]['image_url']['url']);
        elseif ($app === 'happy_horse') self::assertSame(self::IMAGE, $payload['media'][0]['url']);
        else self::assertSame([self::IMAGE], $payload[$field]);
    }

    public function testCanvasAdvancedAudioSettingReachesHappyHorseAdapter(): void
    {
        $option = ['resource_type' => 'app_api', 'params_schema' => [
            'audio_setting' => ['type' => 'string', 'enum' => ['retain', 'replace']],
        ]];
        $input = CanvasGenerationParameters::apply([
            'prompt' => 'fixture prompt', 'duration' => 5,
            'advanced_params' => ['audio_setting' => 'retain'],
        ], $option, 'video');
        $normalized = self::call(AigcCanvasService::class, 'normalizeVideoParams', $input, 1, 1, false);
        $payload = self::call(MarketVideoRuntimeService::class, 'appPayload', [
            'app_code' => 'happy_horse', 'locked_params' => [],
        ], $normalized, 'fixture');
        self::assertSame('retain', $payload['audio_setting']);
    }

    public function testCanvasRejectsInvalidAdvancedAudioSetting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CanvasGenerationParameters::apply(['advanced_params' => ['audio_setting' => 'unsupported']], [
            'resource_type' => 'app_api', 'params_schema' => ['audio_setting' => ['type' => 'string', 'enum' => ['retain', 'replace']]],
        ], 'video');
    }

    /** @dataProvider videoApplications */
    public function testVideoApplicationQuoteAndSubmissionUseSameBillingQuantity(string $app, array $locked, string $field): void
    {
        $market = [
            'product' => ['resource_type' => 'app_api', 'upstream_app_code' => $app, 'source_payload' => []],
            'sku' => ['locked_params' => $locked, 'usage_unit' => 'output_second'],
        ];
        $selection = ['duration' => 5, 'resolution' => '720p'];
        $request = ['duration' => 8, 'resolution' => '720p'];
        $merged = self::call(MarketVideoRuntimeService::class, 'billingRequest', $selection, $request);
        $method = new ReflectionMethod(MarketVideoRuntimeService::class, 'quantity');
        $quoteArgs = [&$market, $request];
        $quoteQuantity = $method->invokeArgs(null, $quoteArgs);
        $submitArgs = [&$market, $merged];
        $submitQuantity = $method->invokeArgs(null, $submitArgs);
        self::assertSame(8.0, $quoteQuantity);
        self::assertSame($quoteQuantity, $submitQuantity);
        $market['sku']['usage_unit'] = 'per_call';
        $callArgs = [&$market, $merged];
        self::assertSame(1.0, $method->invokeArgs(null, $callArgs));
    }
}
