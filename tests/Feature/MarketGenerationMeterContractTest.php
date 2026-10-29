<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\PowerMarketService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketGenerationMeterContractTest extends TestCase
{
    /** @dataProvider formulas */
    public function testStructuredRulesPreserveReviewedLegacyFormulaPrices(string $summary, array $declaration, float $quantity, array $context): void
    {
        $legacy = Meter::contract(['usage_unit'=>'business_unit', 'meter_summary'=>$summary.'，总点数向上取整']);
        $structured = Meter::contract(['usage_unit'=>'business_unit', 'meter_contract'=>['version'=>1, 'rounding'=>'ceil'] + $declaration]);
        foreach ([1.1, 26.0, 68.0] as $price) {
            self::assertSame(
                Meter::price($price, $quantity, ['billing_meter'=>$legacy, 'meter_context'=>$context, 'usage_unit_size'=>2]),
                Meter::price($price, $quantity, ['billing_meter'=>$structured, 'meter_context'=>$context, 'usage_unit_size'=>2])
            );
        }
        self::assertTrue(Meter::isExplicit($structured), 'A machine declaration needs no Chinese summary');
    }

    public static function formulas(): array
    {
        return [
            ['按次', ['basis'=>'call'], 5, []],
            ['生成张数', ['basis'=>'image'], 3, []],
            ['生成秒数', ['basis'=>'output'], 5.5, []],
            ['输入素材秒数', ['basis'=>'input'], 7.25, []],
            ['生成秒数 + 参考视频秒数', ['basis'=>'output_plus_video'], 5, ['reference_video_seconds'=>7.25]],
            ['生成秒数 + （参考视频秒数与生成秒数取较大值）', ['basis'=>'output_plus_max_video'], 5, ['reference_video_seconds'=>2]],
            ['生成张数 + 超过1的参考图片张数×2/56', ['basis'=>'image', 'reference_images'=>['free'=>1,'numerator'=>2,'denominator'=>56]], 1, ['reference_images'=>3]],
            ['生成秒数 + 参考视频秒数 + 超过5的参考图片张数×4/13', ['basis'=>'output_plus_video', 'reference_images'=>['free'=>5,'numerator'=>4,'denominator'=>13]], 5, ['reference_images'=>7,'reference_video_seconds'=>2]],
        ];
    }

    /** @dataProvider invalidDeclarations */
    public function testMalformedOrUnknownExplicitDeclarationCannotFallBack($value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Meter::contract(['usage_unit'=>'per_call', 'meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil'],
            'source_payload'=>['meter_summary'=>'按次，总点数向上取整', 'meter_contract'=>$value]]);
    }

    public static function invalidDeclarations(): array
    {
        $base = ['version'=>1,'basis'=>'image','rounding'=>'ceil'];
        return [[null], [[]], ['{}'], [$base + ['expression'=>'price * count']],
            [array_replace($base,['version'=>2])], [array_replace($base,['version'=>'1'])],
            [array_replace($base,['basis'=>'tokens'])], [array_replace($base,['rounding'=>'eval'])],
            [$base + ['reference_images'=>['free'=>0,'numerator'=>1,'denominator'=>0]]],
            [$base + ['reference_images'=>['free'=>-1,'numerator'=>1,'denominator'=>2]]],
            [$base + ['reference_images'=>['free'=>0,'numerator'=>0.5,'denominator'=>2]]],
            [$base + ['reference_images'=>['free'=>0,'numerator'=>1,'denominator'=>2,'extra'=>1]]],
            [array_replace($base,['basis'=>'call','reference_images'=>['free'=>0,'numerator'=>1,'denominator'=>2]])]];
    }

    public function testSyncPreservesPresenceAndHistoricalSnapshotsKeepTheirOwnRules(): void
    {
        $sync = new ReflectionMethod(PowerMarketService::class, 'skuSnapshot');
        self::assertArrayNotHasKey('meter_contract', $sync->invoke(null, []));
        self::assertArrayHasKey('meter_contract', $sync->invoke(null, ['meter_contract'=>null]));
        $declaration = ['version'=>1,'basis'=>'output','rounding'=>'half_up_6'];
        $saved = $sync->invoke(null, ['meter_contract'=>$declaration]);
        self::assertSame($declaration, $saved['meter_contract']);
        $snapshot = ['billing_meter'=>Meter::contract(['source_payload'=>$saved]), 'usage_unit_size'=>1];
        self::assertSame(1.21, Meter::price(1.1, 1.1, $snapshot));
        Meter::contract(['meter_contract'=>array_replace($declaration, ['rounding'=>'ceil'])]);
        self::assertSame(1.21, Meter::price(1.1, 1.1, $snapshot));
        self::assertSame(1.21, Meter::price(1.1, 1.1, ['usage_unit_size'=>1]));
        self::assertFalse(Meter::isExplicit(Meter::contract(['usage_unit'=>'per_call'])));
    }

    public function testPixelMeterCannotQuoteFromResolutionLabels(): void
    {
        $this->expectExceptionMessage('缺少可核验的像素报价');
        Meter::context(Meter::contract(['meter_contract'=>['version'=>1,'basis'=>'megapixel','rounding'=>'ceil']]), ['resolution'=>'4K']);
    }

    public function testUnmigratedApplicationCannotSilentlyIgnoreNewMeter(): void
    {
        Meter::assertLegacyPricing(['usage_unit'=>'per_call']);
        $this->expectExceptionMessage('尚未接入结构化计费');
        Meter::assertLegacyPricing(['source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']]]);
    }
}
