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
}
