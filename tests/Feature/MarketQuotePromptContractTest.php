<?php

namespace Tests\Feature;

use app\common\service\power\MarketGenerationMeter;
use app\common\service\power\MarketGenerationRequest;
use app\common\service\power\MarketVideoRuntimeService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MarketQuotePromptContractTest extends TestCase
{
    private function snapshot(bool $promptRequired = true): array
    {
        return ['market_metadata' => [
            'params_schema' => ['type' => 'object', 'properties' => [
                'prompt' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20],
                'image_url' => ['type' => 'string'],
                'duration' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ], 'required' => $promptRequired ? ['prompt', 'image_url', 'duration'] : ['image_url', 'duration']],
            'material_capabilities' => ['modes' => [[
                'code' => 'image_to_video', 'fields' => [[
                    'field' => 'image_url', 'kind' => 'image', 'role' => 'first',
                    'required' => true, 'minimum' => 1, 'maximum' => 1,
                ]],
            ]]],
        ]];
    }

    private function request(): array
    {
        return ['generation_method' => 'image_to_video', 'duration' => 5, 'reference_assets' => [[
            'type' => 'image', 'role' => 'first_frame_image', 'url' => 'https://fixtures.invalid/frame.png',
        ]]];
    }

    public function testFuturePromptEstimatePreservesMaterialsAndDoesNotFabricateText(): void
    {
        $request = $this->request();
        $native = MarketGenerationRequest::payload($this->snapshot(), $request, true);
        self::assertArrayNotHasKey('prompt', $native);
        self::assertSame('https://fixtures.invalid/frame.png', $native['image_url']);
        self::assertSame(5, $native['duration']);
        $native = MarketGenerationRequest::payload($this->snapshot(), $request + ['prompt' => 'moving camera']);
        self::assertSame('moving camera', $native['prompt']);
    }

    /** @dataProvider invalidRequests */
    public function testOnlyAnAbsentFuturePromptCanBeDeferred(array $changes, bool $defer): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarketGenerationRequest::payload($this->snapshot(), array_replace($this->request(), $changes), $defer);
    }

    public static function invalidRequests(): array
    {
        return [
            'real submission requires prompt' => [[], false],
            'explicit empty prompt is invalid' => [['prompt' => ''], true],
            'too long prompt remains invalid' => [['prompt' => str_repeat('x', 21)], true],
            'duration remains bounded' => [['duration' => 11], true],
            'first frame remains required' => [['reference_assets' => []], true],
        ];
    }

    public function testReferenceOnlyModelKeepsItsOptionalPrompt(): void
    {
        $native = MarketGenerationRequest::payload($this->snapshot(false), $this->request());
        self::assertArrayNotHasKey('prompt', $native);
    }

    public function testMeterCountsInlineImageReferencesAsWellAsUrls(): void
    {
        $contract = ['basis' => 'image'];
        $context = MarketGenerationMeter::context($contract, ['image_urls' => ['https://fixtures.invalid/a.png', 'data:image/png;base64,YWJj']]);
        self::assertSame(2, $context['reference_images']);
    }
    public function testVideoBillingUsesTheNormalizedSubmittedMaterialsAndPrompt(): void
    {
        $method = new \ReflectionMethod(MarketVideoRuntimeService::class, 'billingRequest');
        $selection = ['market_sku_id' => 12, 'prompt' => '', 'reference_assets' => [['url' => 'stale']]];
        $request = ['prompt' => 'real prompt', 'reference_assets' => [['url' => 'actual frame']], 'duration' => 6];
        $billing = $method->invoke(null, $selection, $request);
        self::assertSame(12, $billing['market_sku_id']);
        self::assertSame('real prompt', $billing['prompt']);
        self::assertSame([['url' => 'actual frame']], $billing['reference_assets']);
        self::assertSame(6, $billing['duration']);
    }

}
