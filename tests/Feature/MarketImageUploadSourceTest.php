<?php
namespace Tests\Feature;

use app\common\service\power\MarketImageReferenceUrlService as References;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketNanoBananaAppRuntimeService as Nano;
use PHPUnit\Framework\TestCase;

/** Browser-shaped upload DTOs. No upload, download, task or wallet writes. */
class MarketImageUploadSourceTest extends TestCase
{
    private const URL='https://fixtures.invalid/uploaded.png';
    private function snapshot(bool $native): array
    {
        $meta=['max_reference_images'=>2,'params_schema'=>[
            'prompt'=>['type'=>'string','required'=>true],
            'image_urls'=>['type'=>'array','items'=>['type'=>'string','pattern'=>'^https?://.+'],'maxItems'=>2],
            'resolution'=>['type'=>'string','enum'=>['1K','2K']], 'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],
            'seed'=>['type'=>'integer','minimum'=>0]]];
        if($native)$meta['material_capabilities']=['modes'=>[
            ['code'=>'text_to_image','fields'=>[]],['code'=>'image_to_image','fields'=>[
                ['field'=>'image_urls','kind'=>'image','role'=>'reference','required'=>true,'maximum'=>2]]]]];
        return ['resource_type'=>'model','model_code'=>'uploaded-image-fixture','channel_code'=>'fixture','locked_params'=>[],
            'market_metadata'=>$meta,'max_reference_images'=>2];
    }
    private function request(): array
    {
        return ['prompt'=>'Offline fixture','quality'=>'2k','ratio'=>'1:1','quantity'=>1,'provider_params'=>['seed'=>0],
            'reference_images'=>[self::URL], 'reference_assets'=>[['id'=>'upload-1','name'=>'Test image','type'=>'image',
                'url'=>'blob:http://localhost:3000/fixture-preview','uri'=>self::URL,'isObjectUrl'=>true,'uploading'=>false]]];
    }
    /** @dataProvider modelProtocols */
    public function testBrowserPreviewNeverReplacesTheUploadedImageInActualPreparation(bool $native): void
    {
        $r=$this->request();$before=$r;$snapshot=$this->snapshot($native);
        $a=Images::prepareRequest($snapshot,$r);$b=Images::prepareRequest($snapshot,$r);
        self::assertSame($a->nativeInput,$b->nativeInput);
        self::assertSame([self::URL],$a->nativeInput['image_urls']);self::assertSame(0,$a->nativeInput['seed']);
        self::assertStringNotContainsString('blob:',json_encode($a->nativeInput));self::assertSame($before,$r);
    }
    public static function modelProtocols(): array{return [[false],[true]];}
    public function testTypedOnlyReferencesWorkInTheSeparateNanoApplicationChain(): void
    {
        $r=$this->request();unset($r['reference_images'],$r['provider_params']);
        $s=['resource_type'=>'app_api','app_code'=>'nano_banana','api_code'=>'submit','model_code'=>'nano-banana-2',
            'locked_params'=>['model'=>'nano-banana-2'],'max_reference_images'=>2,'ratio_options'=>['1:1'],
            'market_metadata'=>['params_schema'=>$this->snapshot(false)['market_metadata']['params_schema']]];
        $p=Nano::prepareRequest($s,$r);
        self::assertSame('edit',$p->nativeInput['action']);self::assertSame([self::URL],$p->nativeInput['image_urls']);
        self::assertSame(self::URL,$p->requestInput['reference_assets'][0]['url']);
    }
    public function testCanonicalizationPreservesRolesFieldsAndDuplicateTypedSlots(): void
    {
        $r=$this->request();$r['reference_assets'][0]+=['field'=>'image_urls','role'=>'reference_image','parameters'=>['strength'=>0]];
        $r['reference_assets'][]=$r['reference_assets'][0];
        $n=References::normalizeRequest($r,0);
        self::assertCount(2,$n['reference_assets']);
        self::assertSame('image_urls',$n['reference_assets'][0]['field']);self::assertSame(0,$n['reference_assets'][0]['parameters']['strength']);
        self::assertSame(self::URL,$n['reference_assets'][1]['url']);
    }
    /** @dataProvider invalidSources */
    public function testBrowserOnlyLocalOrIncompleteReferencesAreRejectedBeforeQuote(string $source,bool $uploading): void
    {
        $r=$this->request();$r['reference_images']=[];$r['reference_assets'][0]['uri']=$source;
        $r['reference_assets'][0]['url']=$source;$r['reference_assets'][0]['uploading']=$uploading;
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($this->snapshot(true),$r);
    }
    public static function invalidSources(): array{return [['blob:http://localhost:3000/preview',false],['file:///tmp/private.png',false],['javascript:fixture',false],['',false],[self::URL,true]];}
    public function testModelMaterialLimitsStillRejectExcessUploads(): void
    {
        $r=$this->request();$r['reference_assets']=array_fill(0,3,$r['reference_assets'][0]);
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($this->snapshot(true),$r);
    }
}
