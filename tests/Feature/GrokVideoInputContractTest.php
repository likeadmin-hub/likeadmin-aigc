<?php
namespace Tests\Feature;
use app\common\service\power\MarketVideoRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;
class GrokVideoInputContractTest extends TestCase
{
    private function invoke(string $method,...$args){return (new \ReflectionMethod(Runtime::class,$method))->invoke(null,...$args);}
    public function testStandardSkuCannotPriceTextOnlyGeneration(): void {
        $p=['resource_type'=>'app_api','upstream_app_code'=>'grok_video'];$locked=['model'=>'grok-imagine-video-1.5'];
        self::assertSame('image_reference',$this->invoke('skuInputMode',$locked));
        self::assertFalse($this->invoke('skuSupportsInputMode','image_reference','text_to_video',$p,$locked));
        self::assertTrue($this->invoke('skuSupportsInputMode','image_reference','image_reference',$p,$locked));
        self::assertFalse($this->invoke('skuSupportsInputMode','image_reference','video_edit',$p,$locked));
    }
    public function testStandardPayloadRequiresAnImageBeforeAnyProviderCall(): void {
        $this->expectExceptionMessage('至少一张参考图片');
        $this->invoke('appPayload',['app_code'=>'grok_video','locked_params'=>['model'=>'grok-imagine-video-1.5']],['prompt'=>'fixture','duration'=>1],'fixture');
    }
    public function testStandardDurationStopsAtFifteenSeconds(): void {
        $this->expectExceptionMessage('1–15');
        $this->invoke('assertSkuMatchesSelection',['product'=>['resource_type'=>'app_api','upstream_app_code'=>'grok_video'],'sku'=>['locked_params'=>['model'=>'grok-imagine-video-1.5']]],['duration'=>16]);
    }
    public function testWanEditDurationDoesNotInheritTextToVideoMaximum(): void {
        $this->expectExceptionMessage('2–10');
        $this->invoke('assertSkuMatchesSelection',['product'=>['resource_type'=>'app_api','upstream_app_code'=>'wan'],'sku'=>['locked_params'=>['model'=>'wan2.7-videoedit']]],['duration'=>11]);
    }
    public function testFastPayloadStillAllowsTextOnlyAndThirtySeconds(): void {
        $r=$this->invoke('appPayload',['app_code'=>'grok_video','locked_params'=>['model'=>'grok-imagine-video-1.5-fast']],['prompt'=>'fixture','duration'=>30],'fixture');
        self::assertSame(30,$r['duration']);self::assertSame('fixture',$r['prompt']);self::assertArrayNotHasKey('image_urls',$r);
    }
    public function testExplicitVariantFiltersEverySkuCandidateWithoutChangingLegacyDefaults(): void {
        $p=['resource_type'=>'app_api','upstream_app_code'=>'grok_video'];
        $fast=['model'=>'grok-imagine-video-1.5-fast'];$standard=['model'=>'grok-imagine-video-1.5'];
        foreach(['fast'=>$fast,'standard'=>$standard] as $mode=>$locked){
            self::assertTrue($this->invoke('skuMatchesGenerationMode',$p,$locked,['mode'=>$mode]));
            self::assertFalse($this->invoke('skuMatchesGenerationMode',$p,$locked,['mode'=>$mode==='fast'?'standard':'fast']));
            self::assertTrue($this->invoke('skuMatchesGenerationMode',$p,$locked,[]));
            self::assertTrue($this->invoke('skuMatchesGenerationMode',$p,$locked,['mode'=>'pro']));
        }
        self::assertTrue($this->invoke('skuMatchesGenerationMode',['upstream_app_code'=>'wan'],$fast,['mode'=>'standard']));
        self::assertTrue($this->invoke('skuMatchesGenerationMode',$p,$standard,['params'=>['mode'=>'standard']]));
        self::assertSame(['fast','standard'],$this->invoke('generationModeOptions',$p,[$fast,$standard]));
        self::assertSame(['standard'],$this->invoke('generationModeOptions',$p,[$standard]));
    }
    public function testStaleFastSkuCannotSilentlySatisfyAnExplicitStandardVariant(): void {
        $this->expectExceptionMessage('不支持当前生成档位');
        $this->invoke('assertSkuMatchesSelection',['product'=>['upstream_app_code'=>'grok_video'],'sku'=>['locked_params'=>['model'=>'grok-imagine-video-1.5-fast']]],['mode'=>'standard']);
    }
    public function testUnregisteredExplicitVariantIsRejectedBeforePricing(): void {
        $this->expectExceptionMessage('不支持所选生成档位');
        $this->invoke('skuMatchesGenerationMode',['upstream_app_code'=>'grok_video'],['model'=>'grok-imagine-video-1.5-fast'],['mode'=>'turbo']);
    }
}
