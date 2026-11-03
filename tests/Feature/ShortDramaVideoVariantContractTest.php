<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;

class ShortDramaVideoVariantContractTest extends TestCase
{
    private function payload(array $params, string $type = 'video'): array
    {
        return (new \ReflectionMethod(ShortDramaCanvasService::class, 'generationPayload'))
            ->invoke(null, $type, ['prompt' => 'Synthetic video'] + $params, 1, 1, 0);
    }

    public function testVideoBusinessVariantIsPreservedWithoutAcceptingBillingOrProviderOverrides(): void
    {
        foreach (['fast', 'standard', 'pro'] as $mode) {
            $payload = $this->payload(['mode' => ' '.strtoupper($mode).' ', 'provider_params' => ['model' => 'untrusted'], 'market_sku_id' => 999]);
            self::assertSame($mode, $payload['mode']);
            self::assertArrayNotHasKey('provider_params', $payload);
            self::assertArrayNotHasKey('market_sku_id', $payload);
        }
        self::assertArrayNotHasKey('mode', $this->payload([]));
        self::assertArrayNotHasKey('mode', $this->payload(['mode' => '']));
        self::assertArrayNotHasKey('mode', $this->payload(['mode' => 'standard'], 'image'));
    }

    public function testMalformedVariantIsRejectedBeforeMarketOrProviderAccess(): void
    {
        $this->expectExceptionMessage('生成档位格式不正确');
        $this->payload(['mode' => ['standard']]);
    }

    public function testCatalogueVariantsOnlyReferenceCurrentPublishedSkuIdentities(): void
    {
        $method = new \ReflectionMethod(MarketVideoRuntimeService::class, 'generationVariants');
        $skus = [
            ['market_sku_id' => 1, 'model' => 'grok-imagine-video-1.5-fast'],
            ['market_sku_id' => 2, 'model' => 'grok-imagine-video-1.5'],
            ['market_sku_id' => 3, 'model' => 'grok-imagine-video-1.5'],
            ['market_sku_id' => 4, 'model' => 'other-model'],
        ];
        self::assertSame([['value' => 'fast', 'market_sku_ids' => [1]], ['value' => 'standard', 'market_sku_ids' => [2, 3]]],
            $method->invoke(null, ['upstream_app_code' => 'grok_video'], $skus));
        self::assertSame([], $method->invoke(null, ['upstream_app_code' => 'wan'], $skus));
        self::assertSame([['value' => 'standard', 'market_sku_ids' => [2]]],
            $method->invoke(null, ['upstream_app_code' => 'grok_video'], [$skus[1]]));
    }
}
