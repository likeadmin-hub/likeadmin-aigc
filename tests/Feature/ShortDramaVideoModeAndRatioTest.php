<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaVideoReferenceMode;
use app\common\service\power\{MarketGenerationMaterials, MarketGenerationNativeContract, MarketVideoRatioPolicy, MarketVideoRuntimeService};
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Native request bodies only; no database, provider, uploads or point writes. */
class ShortDramaVideoModeAndRatioTest extends TestCase
{
    private mixed $previousCatalog;
    private array $metadata;

    protected function setUp(): void
    {
        $property = new ReflectionProperty(MarketGenerationNativeContract::class, 'catalog');
        $this->previousCatalog = $property->getValue();
        $record = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/market/generation-contracts.json'), true)['models']['bytedance-seedance-2-5'];
        $image = static fn(string $field, string $role, int $max = 1): array => ['field' => $field, 'kind' => 'image', 'role' => $role, 'required' => true, 'minimum' => 1, 'maximum' => $max];
        $this->metadata = ['protocol' => 'native_v1', 'upstream_metadata' => ['model_code' => 'bytedance-seedance-2-5'],
            'params_schema' => $record['modes']['']['schema'], 'material_capabilities' => ['modes' => [
                ['code' => '', 'method' => 'text_to_video', 'fields' => []],
                ['code' => '', 'method' => 'image_to_video', 'fields' => [$image('first_frame_url', 'first')]],
                ['code' => '', 'method' => 'start_end', 'fields' => [$image('first_frame_url', 'first'), $image('last_frame_url', 'last')]],
                ['code' => '', 'method' => 'omni_reference', 'fields' => [$image('reference_image_urls', 'reference', 30)]],
            ]]];
        $canonical = static function ($value) use (&$canonical) { if (!is_array($value)) return $value; foreach ($value as &$child) $child = $canonical($child); unset($child); if (!array_is_list($value)) ksort($value); return $value; };
        $record['fingerprint'] = hash('sha256', json_encode($canonical([$this->metadata['params_schema'], MarketGenerationMaterials::contract($this->metadata)]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $property->setValue(null, ['models' => ['bytedance-seedance-2-5' => $record]]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(MarketGenerationNativeContract::class, 'catalog'))->setValue(null, $this->previousCatalog);
    }

    public function testFrameModesAdaptTheProjectRatioAndKeepExactlyTheirFrameFields(): void
    {
        foreach (['image_to_video', 'start_end'] as $method) {
            $request = $this->request($method);
            $ratio = MarketVideoRatioPolicy::normalize($this->metadata, $request);
            self::assertSame('16:9', $ratio['requested_ratio']);
            self::assertSame('adaptive', $ratio['resolved_ratio']);
            self::assertTrue($ratio['ratio_fallback']['applied']);
            $body = $this->prepare(array_replace($request, $ratio));
            self::assertSame('adaptive', $body['aspect_ratio']);
            self::assertSame('https://fixtures.invalid/first.png', $body['first_frame_url']);
            self::assertSame($method === 'start_end', isset($body['last_frame_url']));
            self::assertArrayNotHasKey('reference_image_urls', $body);
            self::assertSame(6, $body['duration']);
        }
    }

    public function testInvalidExplicitFrameRatioIsRejectedBeforeAnySubmission(): void
    {
        $this->expectExceptionMessage('首帧和首尾帧仅支持自适应比例');
        $this->prepare($this->request('image_to_video'));
    }

    public function testTextAndMultipleContextualImagesKeepTheirConcreteRatioWithoutFrameFields(): void
    {
        foreach (['text_to_video', 'omni_reference'] as $method) {
            $request = $this->request($method);
            $ratio = MarketVideoRatioPolicy::normalize($this->metadata, $request);
            self::assertSame('16:9', $ratio['ratio']);
            self::assertFalse($ratio['ratio_fallback']['applied']);
            $body = $this->prepare(array_replace($request, $ratio));
            self::assertSame('16:9', $body['aspect_ratio']);
            self::assertArrayNotHasKey('first_frame_url', $body);
            self::assertArrayNotHasKey('last_frame_url', $body);
            if ($method === 'omni_reference') self::assertCount(3, $body['reference_image_urls']);
        }
    }

    public function testPublishedFrameViewChoicesAreRestrictedWhileReferenceChoicesRemainAvailable(): void
    {
        foreach (MarketGenerationMaterials::modes($this->metadata) as $mode) {
            $choices = $mode['parameter_schema']['properties']['aspect_ratio']['enum'];
            if (in_array($mode['method'], ['image_to_video', 'start_end'], true)) self::assertSame(['adaptive'], $choices);
            else self::assertContains('16:9', $choices);
        }
    }

    public function testContextualReferenceModesArePreferredOverFirstFrameButNotOverRequiredMedia(): void
    {
        $capabilities = ['generation_modes' => ['image_to_video', 'omni_reference', 'multi_frame'], 'material_modes' => MarketGenerationMaterials::modes($this->metadata)];
        self::assertSame('omni_reference', ShortDramaVideoReferenceMode::referenceMethod($capabilities, 3));
        self::assertSame('image_to_video', ShortDramaVideoReferenceMode::referenceMethod($capabilities, 1));
        $capabilities['material_modes'][3]['fields'][] = ['kind' => 'video', 'role' => 'reference', 'required' => true, 'minimum' => 1];
        self::assertSame('image_to_video', ShortDramaVideoReferenceMode::referenceMethod($capabilities, 3));
        self::assertSame('', ShortDramaVideoReferenceMode::referenceMethod($capabilities, 0));
    }

    public function testImageReferenceOnlyModelsAndLegacyAdaptersRemainSupported(): void
    {
        self::assertSame('image_reference', ShortDramaVideoReferenceMode::referenceMethod(['generation_modes' => ['image_reference'],
            'material_modes' => [['method' => 'image_reference', 'fields' => [['kind' => 'image', 'role' => 'reference', 'maximum' => 3]]]]], 3));
        self::assertSame('multi_frame', ShortDramaVideoReferenceMode::referenceMethod(['generation_modes' => ['multi_frame', 'image_to_video']], 3));
        self::assertSame('image_to_video', ShortDramaVideoReferenceMode::referenceMethod(['generation_modes' => ['multi_frame', 'image_to_video'],
            'material_modes' => [['method' => 'multi_frame', 'fields' => [['kind' => 'image', 'role' => 'keyframe', 'maximum' => 10]]]]], 3));
    }

    private function request(string $method): array
    {
        $assets = [];
        if ($method === 'image_to_video' || $method === 'start_end') $assets[] = ['type' => 'image', 'url' => 'https://fixtures.invalid/first.png', 'role' => 'first_frame_image'];
        if ($method === 'start_end') $assets[] = ['type' => 'image', 'url' => 'https://fixtures.invalid/last.png', 'role' => 'last_frame_image'];
        if ($method === 'omni_reference') foreach (range(1, 3) as $i) $assets[] = ['type' => 'image', 'url' => 'https://fixtures.invalid/ref-' . $i . '.png', 'role' => 'reference_image'];
        return ['prompt' => 'Synthetic mode contract', 'generation_method' => $method, 'ratio' => '16:9', 'duration' => 6, 'resolution' => '480p', 'reference_assets' => $assets];
    }

    private function prepare(array $request): array
    {
        return MarketVideoRuntimeService::prepareRequest(['resource_type' => 'model', 'model_code' => 'fixture-video', 'channel_code' => 'fixture',
            'market_metadata' => $this->metadata, 'params_schema' => $this->metadata['params_schema'], 'locked_params' => []], $request)->nativeInput;
    }
}
