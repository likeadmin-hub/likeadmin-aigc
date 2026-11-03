<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Pure request-boundary checks: no project, task, provider or billing writes. */
final class ShortDramaStoryboardVariantContractTest extends TestCase
{
    private function selection(array $request): array
    {
        return (new ReflectionMethod(AigcShortDramaService::class, 'marketVideoSelection'))->invoke(null, $request);
    }

    public function testExplicitStoryboardTierOverridesToolActionAcrossFlatAndNestedInputs(): void
    {
        foreach (['fast', 'standard', 'future-tier'] as $tier) {
            foreach ([false, true] as $nested) {
                $business = ['video_mode' => $tier, 'generate_audio' => false, 'duration' => 0];
                $request = ['mode' => 'video_generate', 'model_id' => 'market_video_app:fixture:108', 'market_sku_id' => 140];
                $request = $nested ? $request + ['params' => $business] : $request + $business;
                $selection = $this->selection($request);
                self::assertSame($tier, $selection['mode']);
                self::assertSame(140, $selection['market_sku_id']);
                self::assertFalse($selection['generate_audio']);
                self::assertSame(0, $selection['duration']);
                self::assertSame('video_generate', $request['mode'], 'Selection cannot rewrite the tool action on its caller.');
            }
        }
    }

    public function testFlatTierWinsWhileAbsentAndExplicitEmptyRemainDistinct(): void
    {
        $base = ['mode' => 'video_generate', 'video_model_id' => 'market_video_app:fixture:108'];
        self::assertSame('standard', $this->selection($base + ['video_mode' => ' standard ', 'params' => ['video_mode' => 'fast']])['mode']);
        self::assertSame('', $this->selection($base + ['video_mode' => '', 'params' => ['video_mode' => 'standard']])['mode']);
        $legacy = $this->selection($base);
        self::assertSame('video_generate', $legacy['mode']);
        self::assertArrayNotHasKey('video_mode', $legacy);
        self::assertSame($base['video_model_id'], $legacy['model_id']);
    }
}
