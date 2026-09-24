<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CanvasVideoReferenceCanonicalizationTest extends TestCase
{
    private const IMAGE_URL = 'https://fixtures.invalid/canvas-image.png';

    public function testConnectedImageAndComposerCopyBecomeOneOwnedFirstFrame(): void
    {
        $references = $this->invoke(ShortDramaCanvasService::class, 'canonicalVideoReferenceAssets', [
            $this->browserImage('first_frame_image'),
            $this->graphImage('reference_image'),
        ]);

        self::assertCount(1, $references);
        self::assertSame('first_frame_image', $references[0]['role']);
        self::assertSame(960, $references[0]['asset_id']);
        self::assertSame('uploads/canvas-image.png', $references[0]['uri']);
    }

    public function testImageToVideoPayloadDoesNotReaddTheSameLegacyImage(): void
    {
        $payload = $this->canvasVideoPayload('image_to_video', [
            $this->browserImage('first_frame_image'),
            $this->graphImage('reference_image', false),
        ]);

        self::assertCount(1, $payload['reference_assets']);
        self::assertSame('first_frame_image', $payload['reference_assets'][0]['role']);
        self::assertSame([], $payload['reference_images']);
        self::assertSame(['text', 'image_url'], array_column(
            $this->invoke(MarketVideoRuntimeService::class, 'h3Content', $payload, $payload['prompt']),
            'type'
        ));
        self::assertSame(1, count(AigcVideoReferenceAssetService::normalize($payload)));
    }

    public function testOmniAndImageReferencePayloadsSubmitOneImage(): void
    {
        foreach (['omni_reference', 'image_reference'] as $method) {
            $payload = $this->canvasVideoPayload($method, [
                $this->browserImage('reference_image'),
                $this->graphImage('reference_image', false),
            ]);
            self::assertCount(1, AigcVideoReferenceAssetService::normalize($payload), $method);
            self::assertSame([], $payload['reference_images'], $method);
        }
    }

    public function testStartEndKeepsTwoDifferentFramesWithoutGraphCopies(): void
    {
        $lastUrl = 'https://fixtures.invalid/last.png';
        $last = ['type' => 'image', 'url' => $lastUrl, 'uri' => $lastUrl, 'role' => 'last_frame_image'];
        $lastGraph = ['type' => 'image', 'url' => $lastUrl, 'uri' => 'uploads/last.png', 'role' => 'reference_image'];
        $payload = $this->canvasVideoPayload('start_end', [
            $this->browserImage('first_frame_image'), $last,
            $this->graphImage('reference_image', false), $lastGraph,
        ], [self::IMAGE_URL, $lastUrl]);

        self::assertSame(['first_frame_image', 'last_frame_image'], array_column($payload['reference_assets'], 'role'));
        self::assertSame([], $payload['reference_images']);
        self::assertCount(2, AigcVideoReferenceAssetService::normalize($payload));
    }

    public function testIdenticalFileMayStillFillBothExplicitFrameSlots(): void
    {
        $payload = $this->canvasVideoPayload('start_end', [
            $this->browserImage('first_frame_image'),
            $this->browserImage('last_frame_image'),
            $this->graphImage('reference_image', false),
        ]);

        self::assertSame(['first_frame_image', 'last_frame_image'], array_column($payload['reference_assets'], 'role'));
        self::assertCount(2, AigcVideoReferenceAssetService::normalize($payload));
    }

    public function testOneDuplicatedImageCannotMasqueradeAsTwoFrameReferences(): void
    {
        $payload = $this->canvasVideoPayload('multi_frame', [
            $this->browserImage('reference_image'),
            $this->graphImage('reference_image', false),
        ]);

        self::assertCount(1, AigcVideoReferenceAssetService::normalize($payload));
        $market = ['product' => [
            'upstream_model_code' => 'wan3.0-video',
            'source_payload' => ['market_metadata' => [
                'supported_asset_types' => ['image'],
                'capabilities' => ['max_reference_images' => 3, 'max_reference_assets' => 3],
            ]],
        ]];
        $this->expectExceptionMessage('multi-frame generation requires at least two reference images');
        $this->invoke(MarketVideoRuntimeService::class, 'assertAssets', $market, $payload);
    }

    public function testASeparateManualReferenceRemainsInTheRequest(): void
    {
        $otherUrl = 'https://fixtures.invalid/other.png';
        $payload = $this->canvasVideoPayload('image_reference', [
            $this->browserImage('reference_image'),
            $this->graphImage('reference_image', false),
            ['type' => 'image', 'url' => $otherUrl, 'role' => 'reference_image'],
        ]);

        self::assertCount(2, AigcVideoReferenceAssetService::normalize($payload));
        self::assertSame([self::IMAGE_URL, $otherUrl], array_column($payload['reference_assets'], 'url'));
    }

    public function testVideoAndAudioReferencesAlsoDeduplicateWithoutMergingDifferentMediaTypes(): void
    {
        $url = 'https://fixtures.invalid/shared-reference';
        $references = $this->invoke(ShortDramaCanvasService::class, 'canonicalVideoReferenceAssets', [
            ['type' => 'video', 'uri' => $url, 'url' => $url, 'role' => 'reference_video'],
            ['type' => 'video', 'uri' => 'uploads/reference.mp4', 'url' => $url, 'role' => 'reference_video', 'asset_id' => 61],
            ['type' => 'audio', 'uri' => $url, 'url' => $url, 'role' => 'reference_audio'],
            ['type' => 'audio', 'uri' => 'uploads/reference.mp3', 'url' => $url, 'role' => 'reference_audio', 'asset_id' => 62],
        ]);

        self::assertSame(['video', 'audio'], array_column($references, 'type'));
        self::assertSame([61, 62], array_column($references, 'asset_id'));
    }

    private function browserImage(string $role): array
    {
        return ['type' => 'image', 'uri' => self::IMAGE_URL, 'url' => self::IMAGE_URL, 'role' => $role];
    }

    private function graphImage(string $role, bool $owned = true): array
    {
        return ['type' => 'image', 'uri' => 'uploads/canvas-image.png', 'url' => self::IMAGE_URL, 'role' => $role]
            + ($owned ? ['asset_id' => 960] : []);
    }

    private function canvasVideoPayload(string $method, array $references, array $images = [self::IMAGE_URL]): array
    {
        return $this->invoke(ShortDramaCanvasService::class, 'generationPayload', 'video', [
            'prompt' => 'Synthetic canvas shot',
            'generation_method' => $method,
            'reference_assets' => $references,
            'reference_images' => $images,
        ], 1, 1, 1);
    }

    private function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
