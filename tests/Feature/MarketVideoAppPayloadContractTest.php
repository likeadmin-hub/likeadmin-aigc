<?php

namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketVideoAppPayloadContractTest extends TestCase
{
    public function testSeedanceUsesDocumentedMultimodalContentAndUploadedAssetIds(): void
    {
        $payload = $this->invoke('appPayload', [
            'app_code' => 'seedance', 'locked_params' => ['resolution' => '720p'],
        ], [
            'prompt' => '让图1随视频1的节奏移动，并参考音频1',
            'duration' => 5, 'ratio' => '9:16', 'generate_audio' => true,
            'reference_assets' => [
                ['type' => 'image', 'url' => 'asset://image-id', 'role' => 'reference_image'],
                ['type' => 'video', 'url' => 'asset://video-id', 'role' => 'reference_video'],
                ['type' => 'audio', 'url' => 'asset://audio-id', 'role' => 'reference_audio'],
            ],
        ], 'seedance-fixture');

        self::assertSame('seedance-2-video-2-video', $payload['model']);
        self::assertSame([
            ['type' => 'text', 'text' => '让图1随视频1的节奏移动，并参考音频1'],
            ['type' => 'image_url', 'role' => 'reference_image', 'image_url' => ['url' => 'asset://image-id']],
            ['type' => 'video_url', 'role' => 'reference_video', 'video_url' => ['url' => 'asset://video-id']],
            ['type' => 'audio_url', 'role' => 'reference_audio', 'audio_url' => ['url' => 'asset://audio-id']],
        ], $payload['content']);
        self::assertArrayNotHasKey('image_urls', $payload);
        self::assertArrayNotHasKey('video_urls', $payload);
        self::assertArrayNotHasKey('audio_urls', $payload);
        self::assertTrue($payload['generate_audio']);
    }

    public function testSeedancePreservesFirstAndLastFrameRolesWithTheSameSource(): void
    {
        $payload = $this->invoke('appPayload', ['app_code' => 'seedance', 'locked_params' => []], [
            'prompt' => '镜头过渡',
            'reference_assets' => [
                ['type' => 'image', 'url' => 'asset://same-id', 'role' => 'first_frame_image'],
                ['type' => 'image', 'url' => 'asset://same-id', 'role' => 'last_frame_image'],
            ],
        ], 'frame-fixture');

        self::assertSame('seedance-2-text-2-video', $payload['model']);
        self::assertSame(['first_frame', 'last_frame'], array_column(array_slice($payload['content'], 1), 'role'));
        self::assertSame('asset://same-id', $payload['content'][1]['image_url']['url']);
        self::assertSame('asset://same-id', $payload['content'][2]['image_url']['url']);
    }

    public function testSeedanceKeepsPricingVariantLocalAndPassesOnlyDocumentedOptions(): void
    {
        $payload = $this->invoke('appPayload', [
            'app_code' => 'seedance',
            'locked_params' => ['resolution' => '720p', '_pricing_variant' => 'without_video'],
        ], [
            'prompt' => 'A quiet scene', 'duration' => 5, 'seed' => 42,
            'watermark' => false, 'unrelated_model_option' => 'must-not-leak',
        ], 'seedance-options');

        self::assertSame(42, $payload['seed']);
        self::assertFalse($payload['watermark']);
        self::assertArrayNotHasKey('_pricing_variant', $payload);
        self::assertArrayNotHasKey('unrelated_model_option', $payload);
    }

    public function testWanReferenceVariantUsesImageWithRolesAndSingleAudioUrl(): void
    {
        $payload = $this->invoke('appPayload', [
            'app_code' => 'wan', 'locked_params' => ['model' => 'wan2.7-r2v', 'resolution' => '720p'],
        ], [
            'prompt' => '保持人物一致', 'duration' => 5, 'ratio' => '9:16',
            'reference_assets' => [
                ['type' => 'image', 'url' => 'https://fixtures.invalid/person.png', 'role' => 'reference_image'],
                ['type' => 'image', 'url' => 'https://fixtures.invalid/scene.png', 'role' => 'first_frame_image'],
                ['type' => 'audio', 'url' => 'https://fixtures.invalid/voice.mp3', 'role' => 'reference_audio'],
            ],
        ], 'wan-fixture');

        self::assertSame('wan2.7-r2v', $payload['model']);
        self::assertSame([
            ['url' => 'https://fixtures.invalid/person.png', 'role' => 'reference_image'],
            ['url' => 'https://fixtures.invalid/scene.png', 'role' => 'first_frame'],
        ], $payload['image_with_roles']);
        self::assertSame('https://fixtures.invalid/voice.mp3', $payload['audio_url']);
        self::assertArrayNotHasKey('image_urls', $payload);
        self::assertArrayNotHasKey('audio_urls', $payload);
    }

    public function testWanVideoEditKeepsItsSeparateDocumentedMediaFields(): void
    {
        $payload = $this->invoke('appPayload', [
            'app_code' => 'wan', 'locked_params' => ['model' => 'wan2.7-videoedit'],
        ], [
            'prompt' => '改变环境',
            'reference_assets' => [
                ['type' => 'video', 'url' => 'https://fixtures.invalid/input.mp4', 'role' => 'reference_video'],
                ['type' => 'image', 'url' => 'https://fixtures.invalid/look.png', 'role' => 'reference_image'],
            ],
        ], 'wan-edit-fixture');

        self::assertSame(['https://fixtures.invalid/input.mp4'], $payload['video_urls']);
        self::assertSame(['https://fixtures.invalid/look.png'], $payload['image_urls']);
        self::assertArrayNotHasKey('image_with_roles', $payload);
    }

    public function testWanRejectsMoreThanOneAudioBeforeBilling(): void
    {
        $this->expectExceptionMessage('only one reference audio');
        $this->invoke('assertWanAppAssets', ['sku' => ['locked_params' => ['model' => 'wan2.7-r2v']]], [
            'image' => ['https://fixtures.invalid/person.png'],
            'video' => [],
            'audio' => ['https://fixtures.invalid/one.mp3', 'https://fixtures.invalid/two.mp3'],
        ]);
    }

    public function testHappyHorseDistinguishesOneImageReferenceFromFirstFrame(): void
    {
        $snapshot = ['app_code' => 'happy_horse', 'locked_params' => ['resolution' => '720p']];
        $image = ['type' => 'image', 'url' => 'https://fixtures.invalid/person.png'];
        $reference = $this->invoke('appPayload', $snapshot, [
            'prompt' => 'Keep the character', 'generation_method' => 'image_reference',
            'ratio' => '9:16', 'reference_assets' => [$image],
        ], 'happy-reference');
        $firstFrame = $this->invoke('appPayload', $snapshot, [
            'prompt' => 'Animate the first frame', 'generation_method' => 'image_to_video',
            'ratio' => '9:16', 'reference_assets' => [array_merge($image, ['role' => 'first_frame_image'])],
        ], 'happy-first-frame');
        $omniReference = $this->invoke('appPayload', $snapshot, [
            'prompt' => 'Keep the character', 'generation_method' => 'omni_reference',
            'reference_assets' => [$image],
        ], 'happy-omni-reference');

        self::assertSame('happyhorse-1.1-r2v', $reference['model']);
        self::assertSame('9:16', $reference['ratio']);
        self::assertSame('happyhorse-1.1-i2v', $firstFrame['model']);
        self::assertArrayNotHasKey('ratio', $firstFrame);
        self::assertSame('happyhorse-1.1-r2v', $omniReference['model']);
    }

    public function testHappyHorseVideoEditRejectsSixthReferenceImageBeforeBilling(): void
    {
        $this->expectExceptionMessage('at most five images');
        $this->invoke('assertHappyHorseAssets', ['sku' => ['locked_params' => []]], [
            'image' => array_fill(0, 6, 'https://fixtures.invalid/reference.png'),
            'video' => ['https://fixtures.invalid/input.mp4'],
            'audio' => [],
        ], 'video_edit');
    }

    public function testFirstLastModeIsExposedOnlyForDocumentedProviders(): void
    {
        foreach ([
            ['resource_type' => 'model', 'upstream_model_code' => 'veo3.1-fast'],
            ['resource_type' => 'app_api', 'upstream_app_code' => 'full_video'],
            ['resource_type' => 'app_api', 'upstream_app_code' => 'seedance'],
            ['resource_type' => 'app_api', 'upstream_app_code' => 'wan'],
        ] as $product) {
            self::assertTrue($this->invoke('supportsStartEndFrames', $product));
        }
        self::assertFalse($this->invoke('supportsStartEndFrames', [
            'resource_type' => 'app_api', 'upstream_app_code' => 'happy_horse',
        ]));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MarketVideoRuntimeService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
