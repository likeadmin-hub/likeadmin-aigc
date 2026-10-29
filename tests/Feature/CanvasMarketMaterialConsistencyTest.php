<?php

namespace Tests\Feature;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\power\MarketGenerationMaterials;
use app\common\service\power\MarketGenerationRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CanvasMarketMaterialConsistencyTest extends TestCase
{
    private function normalize(string $type, array $input): array
    {
        return (new ReflectionMethod(AigcCanvasService::class, 'normalize' . $type . 'Params'))
            ->invoke(null, $input, 1, 1, false);
    }

    private function videoMetadata(): array
    {
        return [
            'params_schema' => ['properties' => [
                'prompt' => ['type' => 'string'],
                'first_clip_url' => ['type' => 'string'],
                'reference_video' => ['type' => 'string'],
            ]],
            'material_capabilities' => ['modes' => [
                ['code' => 'continuation', 'view_key' => 'continuation', 'method' => 'omni_reference', 'fields' => [
                    ['field' => 'first_clip_url', 'kind' => 'video', 'role' => 'reference', 'required' => true, 'minimum' => 1, 'maximum' => 1],
                ]],
                ['code' => 'reference', 'view_key' => 'reference', 'method' => 'omni_reference', 'fields' => [
                    ['field' => 'reference_video', 'kind' => 'video', 'role' => 'reference', 'required' => false, 'minimum' => 0, 'maximum' => 1],
                ]],
            ]],
        ];
    }

    public function testVideoQuoteAndSubmissionKeepExplicitModeAndNativeField(): void
    {
        $input = ['prompt' => 'test', 'generation_method' => 'omni_reference', 'material_mode' => 'reference',
            'reference_assets' => [['type' => 'video', 'role' => 'reference_video', 'url' => 'https://fixture.invalid/a.mp4']]];
        $request = $this->normalize('Video', $input);
        $metadata = $this->videoMetadata();
        $quoteRequest = array_replace($input, $request);
        self::assertSame('reference', MarketGenerationMaterials::select($metadata, $request)['key']);
        $quotePayload = MarketGenerationRequest::payload(['market_metadata' => $metadata], $quoteRequest);
        $submitPayload = MarketGenerationRequest::payload(['market_metadata' => $metadata], $request);
        self::assertSame($quotePayload, $submitPayload);
        self::assertSame('https://fixture.invalid/a.mp4', $submitPayload['reference_video']);
        self::assertArrayNotHasKey('first_clip_url', $submitPayload);
    }

    public function testInvalidExplicitModeIsStillRejected(): void
    {
        $request = $this->normalize('Video', ['material_mode' => 'invalid', 'generation_method' => 'omni_reference']);
        $this->expectException(InvalidArgumentException::class);
        MarketGenerationMaterials::select($this->videoMetadata(), $request);
    }

    public function testMissingModeStillUsesExistingInference(): void
    {
        $request = $this->normalize('Video', ['generation_method' => 'omni_reference']);
        self::assertArrayNotHasKey('material_mode', $request);
        self::assertSame('reference', MarketGenerationMaterials::select($this->videoMetadata(), $request)['key']);
    }

    public function testImageReferencesArePreservedAndValidatedAgainstModel(): void
    {
        $references = array_map(static fn($i) => "https://fixture.invalid/$i.png", range(1, 16));
        $metadata = [
            'params_schema' => ['properties' => [
                'prompt' => ['type' => 'string'], 'images' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'material_capabilities' => ['modes' => [[
                'code' => 'image', 'view_key' => 'image:reference', 'method' => 'image_reference', 'fields' => [[
                    'field' => 'images', 'kind' => 'image', 'role' => 'reference', 'required' => true, 'minimum' => 1, 'maximum' => 16,
                ]],
            ]]],
        ];
        $input = ['prompt' => 'test', 'material_mode' => 'image:reference', 'reference_images' => [...$references, $references[0]]];
        $request = $this->normalize('Image', $input);
        self::assertSame($references, $request['reference_images']);
        $payload = MarketGenerationRequest::payload(['market_metadata' => $metadata], $request);
        self::assertSame($references, $payload['images']);
        $input['reference_images'][] = 'https://fixture.invalid/17.png';
        $this->expectException(InvalidArgumentException::class);
        MarketGenerationRequest::payload(['market_metadata' => $metadata], $this->normalize('Image', $input));
    }
}
