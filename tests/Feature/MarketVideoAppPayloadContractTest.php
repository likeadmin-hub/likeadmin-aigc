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

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MarketVideoRuntimeService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
