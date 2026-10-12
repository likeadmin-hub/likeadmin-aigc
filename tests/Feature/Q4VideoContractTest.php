<?php
namespace Tests\Feature;

use app\common\service\power\Q4VideoContract;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class Q4VideoContractTest extends TestCase
{
    private function payload(array $request = [], array $locked = []): array
    {
        return (new ReflectionMethod(MarketVideoRuntimeService::class, 'modelPayload'))->invoke(null,
            ['model_code'=>'q4-preview-video','locked_params'=>$locked],
            array_replace(['reference_images'=>['https://example.test/frame.png']], $request), 'q4-contract-test');
    }

    public function testDocumentedDefaultsAllowAnEmptyPromptAndUseImages(): void
    {
        $out=$this->payload();
        self::assertSame(['https://example.test/frame.png'],$out['images']);
        self::assertSame('', $out['prompt']);
        self::assertSame(5,$out['duration']);
        self::assertSame('720p',$out['resolution']);
        self::assertTrue($out['audio']);
        self::assertTrue($out['is_rec']);
        self::assertTrue($out['watermark']);
        self::assertSame(0,$out['seed']);
        self::assertSame(3,$out['wm_position']);
        self::assertArrayNotHasKey('image_urls',$out);
        self::assertArrayNotHasKey('ratio',$out);
    }

    public function testAllResolutionsAndDurationsPreserveCanonicalCase(): void
    {
        foreach(['540p','720p','1080p','2K','4K'] as $resolution) {
            foreach(range(3,16) as $duration) {
                $out=$this->payload(['quality'=>strtolower($resolution),'duration'=>$duration]);
                self::assertSame($resolution,$out['resolution']);
                self::assertSame($duration,$out['duration']);
            }
        }
    }

    public function testAdvancedOptionsPreserveFalseAndEncodedMetadata(): void
    {
        $request=['audio'=>false,'is_rec'=>false,'seed'=>0,'watermark'=>true,'wm_position'=>4,
            'wm_url'=>'https://example.test/watermark.png','payload'=>'order-123',
            'meta_data'=>'{"Label":"1"}','callback_url'=>'https://example.test/callback'];
        $out=$this->payload($request);
        foreach($request as $key=>$value)self::assertSame($value,$out[$key]);
        $out=$this->payload(array_replace($request,['watermark'=>false]));
        self::assertFalse($out['watermark']);
        self::assertArrayNotHasKey('wm_position',$out);
        self::assertArrayNotHasKey('wm_url',$out);
    }

    public function testSkuLocksRemainAuthoritative(): void
    {
        $out=$this->payload(['quality'=>'720p','duration'=>8,'seed'=>1],['resolution'=>'4K','duration'=>12,'seed'=>42]);
        self::assertSame('4K',$out['resolution']);
        self::assertSame(12,$out['duration']);
        self::assertSame(42,$out['seed']);
    }

    /** @dataProvider invalidRequests */
    public function testInvalidInputsFailBeforeProviderSubmission(array $request): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->payload($request);
    }
    public static function invalidRequests(): array
    {
        return array_map(fn($request)=>[$request],[
            ['reference_images'=>[]],['reference_images'=>['https://example.test/a.png','https://example.test/b.png']],
            ['duration'=>2],['duration'=>17],['quality'=>'8K'],['seed'=>-1],['wm_position'=>5],
            ['meta_data'=>['Label'=>'1']],['meta_data'=>'{'],['callback_url'=>'file:///tmp/hook'],
            ['audio'=>'invalid'],['prompt'=>str_repeat('a',20001)],
        ]);
    }

    public function testCapabilityFallbackNeedsNoDatabaseOverrideAndIsModelScoped(): void
    {
        $product=['upstream_model_code'=>'q4-preview-video'];
        $meta=Q4VideoContract::metadata($product,[]);
        self::assertSame(['image_to_video'],$meta['generation_modes']);
        self::assertSame(1,$meta['max_reference_images']);
        self::assertSame(range(3,16),$meta['supported_durations']);
        self::assertSame(['540p','720p','1080p','2K','4K'],$meta['params_schema']['resolution']['enum']);
        self::assertSame(['existing'=>true],Q4VideoContract::metadata(['model_code'=>'h3-video'],['existing'=>true]));
        $sku=new ReflectionMethod(MarketVideoRuntimeService::class,'skuSupportsInputMode');
        self::assertTrue($sku->invoke(null,'text_to_video','image_reference',$product,['model'=>'q4-preview-video']));
        self::assertFalse($sku->invoke(null,'text_to_video','text_to_video',$product,[]));
    }
}
