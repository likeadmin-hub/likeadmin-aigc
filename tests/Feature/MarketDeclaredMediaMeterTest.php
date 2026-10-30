<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationMediaInputs as Inputs;
use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Synthetic contracts and pure preparation; never creates a supplier task. */
class MarketDeclaredMediaMeterTest extends TestCase
{
    private const URL='https://fixtures.invalid/opaque-file';
    private function metadata(): array
    {
        return ['supports_reference_images'=>true,'params_schema'=>['prompt'=>['type'=>'string','required'=>true],
            'files_url'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>2]],
            'material_capabilities'=>['modes'=>[['code'=>'image_to_image','view_key'=>'files',
                'method'=>'image_reference','fields'=>[['field'=>'files_url','kind'=>'image',
                    'role'=>'reference','maximum'=>2]]]]]];
    }
    private function market(): array
    {
        return ['product'=>['id'=>990021,'resource_type'=>'model','upstream_model_code'=>'synthetic-files-image',
            'upstream_channel_code'=>'fixture','source_payload'=>['market_metadata'=>$this->metadata()]],
            'sku'=>['id'=>990022,'sku_key'=>'fixture','locked_params'=>[],'usage_unit'=>'per_image',
                'usage_unit_size'=>1,'upstream_price'=>3,'sale_points'=>3,'source_payload'=>[
                    'meter_contract'=>['version'=>1,'basis'=>'image','rounding'=>'ceil',
                        'reference_images'=>['free'=>1,'numerator'=>1,'denominator'=>2]]]],
            'tenant_price'=>3,'reference_limit'=>2];
    }
    private function request(): array
    {
        return ['prompt'=>'Offline fixture','quantity'=>1,'material_mode'=>'files',
            'reference_images'=>[self::URL,self::URL]];
    }
    private function snapshot(): array
    {
        return (new ReflectionMethod(Images::class,'snapshot'))->invoke(null,$this->market());
    }
    public function testGenericFilesAreNotImagesWithoutTheirDeclaredKind(): void
    {
        self::assertSame(['image'=>[],'video'=>[],'audio'=>[]],Inputs::extract(['files_url'=>[self::URL.'.png']]));
        $native=['files_url'=>[self::URL,self::URL],'image_url'=>self::URL,'prompt'=>self::URL,
            'description'=>self::URL,'input'=>['files_url'=>[self::URL]]];
        self::assertCount(3,Inputs::extract($native,['files_url'=>'image'])['image']);
        self::assertSame([self::URL,self::URL],Inputs::extract(['files_url'=>[self::URL,self::URL]],['files_url'=>'audio'])['audio']);
    }
    public function testInvalidKindsDoNotInventTypesOrCrash(): void
    {
        foreach ([[],null,false,'file','IMAGE'] as $invalid) {
            self::assertSame([],Inputs::extract(['files_url'=>[self::URL]],['files_url'=>$invalid])['image']);
        }
        self::assertSame([],Inputs::extract(['prompt'=>self::URL],['prompt'=>'image'])['image']);
    }
    public function testOnlySelectedUnambiguousTopLevelFieldsClassifyFiles(): void
    {
        $m=$this->metadata();
        $m['material_capabilities']['modes'][]=['code'=>'audio_reference','view_key'=>'audio',
            'method'=>'audio_reference','fields'=>[['field'=>'files_url','kind'=>'audio','role'=>'reference']]];
        self::assertSame(['files_url'=>'image'],Materials::nativeMediaKinds($m,$this->request()));
        self::assertSame(['files_url'=>'audio'],Materials::nativeMediaKinds($m,['material_mode'=>'audio']));
        $m['material_capabilities']['modes'][0]['fields'][]=['field'=>'files_url','kind'=>'audio','role'=>'reference'];
        self::assertSame([],Materials::nativeMediaKinds($m,$this->request()));
        self::assertSame([],Materials::nativeMediaKinds([],[]));
    }
    public function testNewQuoteSubmissionAndFrozenPreparationUseDeclaredSlotCounts(): void
    {
        $quoted=(new ReflectionMethod(Images::class,'meterMarket'))->invoke(null,$this->market(),$this->request());
        $s=(new ReflectionMethod(Images::class,'snapshot'))->invoke(null,$quoted);
        $p=Images::prepareRequest($s,$this->request());
        self::assertSame(1,$s['media_meter_revision']);
        self::assertSame([self::URL,self::URL],$p->nativeInput['files_url']);
        self::assertSame(2,$p->meterContext['reference_images']);
        self::assertSame($quoted['meter_context'],$p->meterContext);
        self::assertSame(5.0,Meter::price(3,1,$s));
        $f=Images::prepareRequest(json_decode(json_encode($s),true),$this->request());
        self::assertSame($p->nativeInput,$f->nativeInput);
        self::assertSame($p->meterContext,$f->meterContext);
    }
    public function testHistoricalSnapshotsRetainTheirOriginalCountingAndPrices(): void
    {
        $quoted=(new ReflectionMethod(Images::class,'meterMarket'))->invoke(null,$this->market(),$this->request());
        $s=(new ReflectionMethod(Images::class,'snapshot'))->invoke(null,$quoted);
        unset($s['media_meter_revision']);$s['meter_context']['reference_images']=0;
        $before=$s;$p=Images::prepareRequest($s,$this->request());
        self::assertSame([self::URL,self::URL],$p->nativeInput['files_url']);
        self::assertSame(0,$p->meterContext['reference_images']);
        self::assertSame(3.0,Meter::price(3,1,$s));
        self::assertSame($before,$s);
    }
    public function testUnclassifiedNewSurchargeCannotSilentlyQuoteZeroReferences(): void
    {
        $s=$this->snapshot();unset($s['market_metadata']['material_capabilities']);
        $s['billing_meter']=Meter::contract($this->market()['sku']);
        $s['max_reference_images']=2;
        $r=$this->request();unset($r['material_mode']);
        $r['provider_params']=['files_url'=>[self::URL,self::URL]];
        $this->expectExceptionMessage('参考素材计费数量尚不能完整核验');
        Images::prepareRequest($s,$r);
    }
    public function testNewFixedPriceGenericFieldsDoNotAcquireASurcharge(): void
    {
        $s=$this->snapshot();$p=Images::prepareRequest($s,$this->request());
        self::assertNull($p->billingMeter);
        self::assertSame([],$p->meterContext);
        self::assertSame(3.0,Meter::price(3,1,$s));
    }
}
