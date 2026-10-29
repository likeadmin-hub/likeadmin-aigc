<?php
namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageChannelService as Channels;
use PHPUnit\Framework\TestCase;

/** Exercise the real selector with trusted synthetic catalogue rows; no database. */
class MarketImageChannelSkuSelectionTest extends TestCase
{
    private function resolve(array $params): array
    {
        $ratios=[['value'=>'1:1','market_sku_id'=>11,'tenant_unit_price'=>2],
            ['value'=>'1:1','market_sku_id'=>12,'tenant_unit_price'=>7],
            ['value'=>'16:9','market_sku_id'=>13,'tenant_unit_price'=>9]];
        $channels=[['code'=>'market_image_model:999001','qualities'=>[['value'=>'1K','ratios'=>$ratios]]]];
        return (new \ReflectionMethod(Channels::class,'resolveFromChannels'))->invoke(null,$channels,
            array_replace(['channel'=>'market_image_model:999001','quality'=>'1K','ratio'=>'1:1'],$params));
    }
    public function testExplicitSkuKeepsItsOwnPriceWhenDimensionsAreShared(): void
    {
        $chosen=$this->resolve(['market_sku_id'=>12]);
        self::assertSame(12,$chosen['spec']['market_sku_id']);self::assertSame(7,$chosen['spec']['tenant_unit_price']);
        self::assertSame(11,$this->resolve([])['spec']['market_sku_id']);
    }
    public function testDimensionChangeStillNegotiatesWithinTheSelectedChannel(): void
    {
        self::assertSame(13,$this->resolve(['market_sku_id'=>12,'ratio'=>'16:9'])['spec']['market_sku_id']);
    }
    /** @dataProvider unsupported */
    public function testSkuPreferenceCannotBypassRatioResolutionOrChannelChecks(array $params): void
    {
        $this->expectException(\Exception::class);$this->resolve($params+['market_sku_id'=>12]);
    }
    public static function unsupported(): array{return [[['ratio'=>'4:3']],[['quality'=>'4K']],[['channel'=>'other-model']]];}
}
