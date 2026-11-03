<?php
namespace Tests\Feature;

use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketGenerationSchema as Schema;
use PHPUnit\Framework\TestCase;

class MarketImageRatioDimensionsTest extends TestCase
{
    private function metadata(): array
    {
        return ['params_schema'=>['prompt'=>['type'=>'string'],'image_size'=>['type'=>'string','enum'=>['1:1','16:9','auto']]],
            'material_capabilities'=>['modes'=>[['code'=>'text_to_image','fields'=>[]]]]];
    }
    public function testRatioSizeDoesNotBecomeAnIndependentQualitySelector(): void
    {
        $meta=$this->metadata();
        self::assertSame([],(new \ReflectionMethod(Images::class,'modelQualityOptions'))->invoke(null,[],$meta));
        self::assertSame(['1:1','16:9','auto'],(new \ReflectionMethod(Images::class,'ratioOptions'))->invoke(null,[],$meta));
        self::assertSame('',(new \ReflectionMethod(Images::class,'skuQuality'))->invoke(null,[],['image_size'=>'16:9']));
    }
    public function testRatioAndIndependentRenderingQualityRemainSeparate(): void
    {
        $meta=$this->metadata();$meta['params_schema']['quality']=['type'=>'string','enum'=>['standard','high']];
        self::assertSame(['standard','high'],(new \ReflectionMethod(Images::class,'modelQualityOptions'))->invoke(null,[],$meta));
    }
    public function testImageSizeSkuLockRestrictsRatioInsteadOfQuality(): void
    {
        self::assertSame(['16:9'],(new \ReflectionMethod(Images::class,'ratioOptions'))->invoke(null,['image_size'=>'16:9'],$this->metadata()));
    }
    public function testRatioSelectionIsTheOnlyNativeSizeValue(): void
    {
        $s=['resource_type'=>'model','model_code'=>'ratio-size-fixture','locked_params'=>[],'market_metadata'=>$this->metadata()];
        self::assertSame('16:9',Images::prepareRequest($s,['prompt'=>'Fixture','ratio'=>'16:9','quality'=>''])->nativeInput['image_size']);
        $this->expectException(\InvalidArgumentException::class);
        Images::prepareRequest($s,['prompt'=>'Fixture','ratio'=>'16:9','quality'=>'1:1']);
    }
    public function testResolutionEnumsAndNamedSizePresetsAreNotMisclassifiedAsRatios(): void
    {
        self::assertFalse(Schema::isAspectRatioDefinition(['enum'=>['1K','2K','4K']]));
        self::assertFalse(Schema::isAspectRatioDefinition(['enum'=>['square','landscape_16_9']]));
        self::assertFalse(Schema::isAspectRatioDefinition(['example'=>'1:1']));
        self::assertFalse(Schema::isAspectRatioDefinition(['enum'=>['1:1','2K']]));
        $meta=$this->metadata();$meta['params_schema']['image_size']['enum']=['1K','2K','4K'];
        self::assertSame(['1k','2k','4k'],(new \ReflectionMethod(Images::class,'modelQualityOptions'))->invoke(null,[],$meta));
    }
    public function testUndeclaredResolutionDoesNotDisappearFromARatioOnlyModel(): void
    {
        $s=['resource_type'=>'model','model_code'=>'ratio-size-fixture','locked_params'=>[],'market_metadata'=>$this->metadata()];
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($s,['prompt'=>'Fixture','ratio'=>'16:9','quality'=>'2K']);
    }
    public function testAutoSizeMeaningComesFromTheDeclaredDomain(): void
    {
        $method=new \ReflectionMethod(Images::class,'skuQuality');$meta=$this->metadata();
        self::assertSame('',$method->invoke(null,[],['image_size'=>'auto'],$meta));
        $meta['params_schema']['image_size']['enum']=['auto','1024x1024'];
        self::assertSame('auto',$method->invoke(null,[],['image_size'=>'auto'],$meta));
        $meta['params_schema']['image_size']['enum']=['1:1','2K'];
        self::assertSame(['1:1'],(new \ReflectionMethod(Images::class,'ratioOptions'))->invoke(null,[],$meta));
    }

    public function testRequiredSizeRatioFlowsFromCatalogueThroughUploadedImagePreparation(): void
    {
        $meta=['params_schema'=>['size'=>['type'=>'string','required'=>true,'enum'=>['1:1','3:2','2:3']],
            'prompt'=>['type'=>'string'],'files_url'=>['type'=>'array','items'=>['type'=>'string','format'=>'uri'],'maxItems'=>5]],
            'material_capabilities'=>['modes'=>[['code'=>'','fields'=>[
                ['field'=>'files_url','kind'=>'image','role'=>'reference','required'=>false,'maximum'=>5]]]]]];
        self::assertSame(['1:1','3:2','2:3'],(new \ReflectionMethod(Images::class,'ratioOptions'))->invoke(null,[],$meta));
        self::assertSame(['2:3'],(new \ReflectionMethod(Images::class,'ratioOptions'))->invoke(null,['size'=>'2:3'],$meta));
        self::assertSame([],(new \ReflectionMethod(Images::class,'modelQualityOptions'))->invoke(null,[],$meta));
        $snapshot=['resource_type'=>'model','model_code'=>'required-size-fixture','locked_params'=>[],'market_metadata'=>$meta];
        $request=['prompt'=>'Fixture','ratio'=>'3:2','quality'=>'','reference_assets'=>[['type'=>'image',
            'url'=>'blob:http://localhost:3000/fixture','uri'=>'https://fixtures.invalid/reference.png']]];
        $prepared=Images::prepareRequest($snapshot,$request);
        self::assertSame('3:2',$prepared->nativeInput['size']);
        self::assertSame(['https://fixtures.invalid/reference.png'],$prepared->nativeInput['files_url']);
        self::assertArrayNotHasKey('resolution',$prepared->nativeInput);
        self::assertSame($prepared->nativeInput,Images::prepareRequest($snapshot,$request)->nativeInput);
        $request['ratio']='4:3';$this->expectException(\InvalidArgumentException::class);
        Images::prepareRequest($snapshot,$request);
    }

    /** @dataProvider unsupportedSizeDimensions */
    public function testRatioSizeDoesNotSwallowInvalidResolutionOrAnUnselectedRequiredSize(array $request): void
    {
        $meta=$this->metadata();$meta['params_schema']['size']=$meta['params_schema']['image_size'];
        $meta['params_schema']['size']['required']=true;unset($meta['params_schema']['image_size']);
        $snapshot=['resource_type'=>'model','model_code'=>'required-size-fixture','locked_params'=>[],'market_metadata'=>$meta];
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($snapshot,['prompt'=>'Fixture']+$request);
    }
    public static function unsupportedSizeDimensions(): array
    {
        return [[['ratio'=>'16:9','quality'=>'2K']],[['ratio'=>'16:9','quality'=>'1:1']],[[]]];
    }

    public function testPixelSizeEnumsRemainSeparateFromAspectRatios(): void
    {
        $meta=['params_schema'=>['size'=>['type'=>'string','enum'=>['1024x1024','1536x1024']]]];
        self::assertSame([],(new \ReflectionMethod(Images::class,'ratioOptions'))->invoke(null,[],$meta));
    }
}
