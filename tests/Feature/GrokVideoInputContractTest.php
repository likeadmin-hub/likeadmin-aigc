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
    public function testFastPayloadStillAllowsTextOnlyAndThirtySeconds(): void {
        $r=$this->invoke('appPayload',['app_code'=>'grok_video','locked_params'=>['model'=>'grok-imagine-video-1.5-fast']],['prompt'=>'fixture','duration'=>30],'fixture');
        self::assertSame(30,$r['duration']);self::assertSame('fixture',$r['prompt']);self::assertArrayNotHasKey('image_urls',$r);
    }
}
