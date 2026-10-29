<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMediaInputs as Inputs;
use app\common\service\power\MarketGenerationMedia as Media;
use app\common\service\power\MarketGenerationMeter as Meter;
use PHPUnit\Framework\TestCase;

class MarketMixedMediaBillingTest extends TestCase
{
    private function native(): array
    {
        return ['input'=>['prompt'=>'https://fixtures.invalid/prompt','media'=>[
            ['type'=>'first_frame','url'=>'https://fixtures.invalid/frame'],
            ['type'=>'last_frame','url'=>'https://fixtures.invalid/frame'],
            ['type'=>'reference_image','url'=>'https://fixtures.invalid/image'],
            ['type'=>'reference_video','url'=>'https://fixtures.invalid/video','duration'=>9999],
            ['type'=>'reference_audio','url'=>'https://fixtures.invalid/audio','duration'=>9999],
            ['type'=>'file','url'=>'https://fixtures.invalid/file'],['type'=>'link','url'=>'https://fixtures.invalid/page']]],
            'callback_url'=>'https://fixtures.invalid/callback'];
    }
    public function testTypedSlotsAreCountedWithoutUsingPromptCallbackOrBrowserDuration(): void
    {
        $native=$this->native();$before=$native;$inputs=Inputs::extract($native);
        self::assertCount(3,$inputs['image']);self::assertSame([$inputs['image'][0],$inputs['image'][0]],array_slice($inputs['image'],0,2));
        self::assertSame(['https://fixtures.invalid/video'],$inputs['video']);
        self::assertSame(['https://fixtures.invalid/audio'],$inputs['audio']);self::assertSame($before,$native);
        $seen=[];$seconds=Media::seconds($native,false,static function($url,$kind)use(&$seen){$seen[]=[$url,$kind];return $kind==='video'?1.5:3.0;});
        self::assertSame(3.0,$seconds);self::assertCount(2,$seen);
    }
    public function testCompoundMeterMeasuresOnlyActualVideoSlotsAndFreezesItsResult(): void
    {
        $contract=Meter::contract(['meter_contract'=>['version'=>1,'basis'=>'output_plus_video','rounding'=>'half_up_6',
            'reference_images'=>['free'=>1,'numerator'=>1,'denominator'=>2]]]);
        $seen=[];$context=Meter::context($contract,$this->native(),static function($input)use(&$seen){
            $seen=$input;return Media::seconds($input,false,static fn()=>1.5);
        });
        self::assertSame(['video_urls'=>['https://fixtures.invalid/video']],$seen);
        self::assertSame(['reference_images'=>3,'reference_video_seconds'=>1.5],$context);
        $snapshot=['billing_meter'=>$contract,'usage_unit_size'=>1,'meter_context'=>$context];
        self::assertSame(13.0,Meter::price(2.0,5,$snapshot));
    }
    public function testNewExtractionDoesNotRewriteHistoricalFrozenBillingContext(): void
    {
        $contract=Meter::contract(['meter_contract'=>['version'=>1,'basis'=>'output_plus_video','rounding'=>'half_up_6',
            'reference_images'=>['free'=>0,'numerator'=>1,'denominator'=>2]]]);
        $old=['billing_meter'=>$contract,'usage_unit_size'=>1,'meter_context'=>['reference_images'=>0,'reference_video_seconds'=>0]];$before=$old;
        self::assertSame(8.0,Meter::price(2.0,4,$old));
        $new=Meter::context($contract,$this->native(),static fn()=>3.0);
        self::assertSame(3,$new['reference_images']);
        self::assertSame(8.0,Meter::price(2.0,4,$old));self::assertSame($before,$old);
        self::assertSame(17.0,Meter::price(2.0,4,array_replace($old,['meter_context'=>$new])));
    }
    public function testKnownTimedAssetsWithoutMeasurableAddressDoNotGetTheNoInputEstimate(): void
    {
        $called=0;
        try{Media::seconds(['input'=>['media'=>[['type'=>'reference_video','url'=>'asset://opaque']]]],true,
            static function()use(&$called){$called++;return 5.0;});self::fail('Unknown asset duration must be rejected');}
        catch(\InvalidArgumentException$e){self::assertStringContainsString('可核验',$e->getMessage());}
        self::assertSame(0,$called);
    }
    public function testNoTimedInputRetainsTheExistingConservativeEstimate(): void
    {
        self::assertSame(60.0,Media::seconds(['prompt'=>'https://fixtures.invalid/not-a-video'],true,
            static function(){throw new \LogicException('Must not probe a prompt');}));
    }
    /** @dataProvider invalidMeasurements */
    public function testUntrustedOrInvalidMeasurementsCannotPriceARequest($value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Media::seconds(['video_url'=>'https://fixtures.invalid/video'],false,static fn()=>$value);
    }
    public static function invalidMeasurements(): array {return [[0],[-1],[INF],[NAN],['5'],[[]]];}
}
