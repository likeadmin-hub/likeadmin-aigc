<?php
namespace Tests\Feature;

use app\common\service\power\MarketNanoBananaAppRuntimeService as Nano;
use app\common\service\power\MarketGenerationImageBilling as Billing;
use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\MarketCreationParameters;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketNanoBananaPreparationTest extends TestCase
{
    private function metadata(): array
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
        foreach ($fixture['contracts'] as $row) if (($row['app_code'] ?? '') === 'nano_banana') return $row;
        throw new \RuntimeException('Missing captured Nano API declaration');
    }

    private function snapshot(string $model = 'nano-banana-2'): array
    {
        return ['resource_type'=>'app_api','app_code'=>'nano_banana','api_code'=>'submit','model_code'=>$model,
            'locked_params'=>['model'=>$model], 'max_reference_images'=>2, 'ratio_options'=>['1:1','16:9'],
            'market_metadata'=>$this->metadata()];
    }

    private function request(): array
    {
        return ['channel'=>'fixture','prompt'=>'Offline fixture','ratio'=>'1:1','quality'=>'1k','quantity'=>2,
            'reference_images'=>['https://fixtures.invalid/a.png','https://fixtures.invalid/b.png']];
    }

    private function market(array $source = []): array
    {
        return ['product'=>['id'=>991101,'upstream_app_code'=>'nano_banana','upstream_api_code'=>'submit',
                'source_payload'=>['market_metadata'=>$this->metadata()]],
            'sku'=>['id'=>991102,'sku_key'=>'fixture','locked_params'=>['model'=>'nano-banana-2'],
                'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>3,'sale_points'=>3,'source_payload'=>$source],
            'model'=>'nano-banana-2','tenant_price'=>5,'reference_limit'=>2];
    }

    public static function variants(): array
    {
        return array_map(static fn($v)=>[$v],['nano-banana','nano-banana-2','nano-banana-2-lite','nano-banana-pro',
            'nano-banana:official','nano-banana-2:official','nano-banana-2-lite:official','nano-banana-pro:official']);
    }

    /** @dataProvider variants */
    public function testAllCapturedVariantsPrepareGenerateAndEditThroughOneEntry(string $model): void
    {
        $request = $this->request();
        $edit = Nano::prepareRequest($this->snapshot($model),$request,'fixture-key');
        self::assertSame($model,$edit->nativeInput['model']);
        self::assertSame('edit',$edit->nativeInput['action']);
        self::assertSame('1K',$edit->nativeInput['resolution']);
        self::assertSame($request['reference_images'],$edit->nativeInput['image_urls']);
        self::assertSame('fixture-key',$edit->nativeInput['idempotency_key']);
        $request['reference_images'] = [];
        $generate = Nano::prepareRequest($this->snapshot($model),$request);
        self::assertSame('generate',$generate->nativeInput['action']);
        self::assertArrayNotHasKey('image_urls',$generate->nativeInput);
    }

    public function testStandaloneCanvasAndDramaUseTheSamePreparedApplicationRequest(): void
    {
        $snapshot=$this->snapshot();
        $snapshot['market_metadata']['params_schema']['properties'] += ['seed'=>['type'=>'integer','minimum'=>0], 'watermark'=>['type'=>'boolean']];
        $option=['value'=>'fixture','resource_type'=>'app_api','params_schema'=>$snapshot['market_metadata']['params_schema']];
        $input=$this->request()+['advanced_params'=>['seed'=>0,'watermark'=>false]];
        $configured=MarketCreationParameters::applyOptions($input,[$option],'image');
        $canvas=array_replace($configured,(new ReflectionMethod(AigcCanvasService::class,'normalizeImageParams'))->invoke(null,$configured,0,0,false));
        $drama=(new ReflectionMethod(ShortDramaCanvasService::class,'generationPayload'))->invoke(null,'image',$input,0,0,1,false);
        $expected=null;
        foreach([$input,$canvas,$drama] as $request){
            $request=MarketCreationParameters::applyOptions($request,[$option],'image');
            $native=Nano::prepareRequest($snapshot,$request)->nativeInput;
            $expected??=$native;self::assertSame($expected,$native);
            self::assertSame(0,$native['seed']);self::assertFalse($native['watermark']);
        }
    }

    /** @dataProvider meters */
    public function testQuotesAndPartialResultsUsePreparedContextAndSharedSnapshotPricing(array $source): void
    {
        $market=(new ReflectionMethod(Nano::class,'meterMarket'))->invoke(null,$this->market($source),$this->request(),0);
        $quote=(new ReflectionMethod(Nano::class,'quoteMarket'))->invoke(null,$market,2);
        $snapshot=$quote['market_snapshot'];$prepared=Nano::prepareRequest($snapshot,$this->request(),'submit-key');
        self::assertSame($snapshot['meter_context'],$prepared->meterContext);
        self::assertSame(2,$prepared->meterContext['reference_images']);
        self::assertSame([8.0,13.0],[$quote['tenant_cost_points'],$quote['user_charge_points']]);
        self::assertSame([5.0,8.0],Billing::amounts($snapshot,1));
        $snapshot['fixed_user_unit_price']=9;
        self::assertSame([8.0,18.0],Billing::amounts($snapshot,2));
        self::assertTrue(Meter::isExplicit($snapshot['billing_meter']));
    }

    public static function meters(): array
    {
        return [[['meter_summary'=>'生成张数 + 超过1的参考图片张数×1/2，总点数向上取整']],
            [['meter_contract'=>['version'=>1,'basis'=>'image','rounding'=>'ceil','reference_images'=>['free'=>1,'numerator'=>1,'denominator'=>2]]]]];
    }

    public function testHistoricalApplicationAndModelSnapshotSemanticsRemainDistinct(): void
    {
        self::assertSame([6.0,10.0],Billing::amounts(['app_code'=>'nano_banana','usage_unit_size'=>1000000,'platform_price'=>3,'tenant_price'=>5],2));
        self::assertSame([3.0,5.0],Billing::amounts(['usage_unit_size'=>2,'platform_price'=>3,'tenant_price'=>5],2));
        self::assertSame([3.0,5.0],Billing::amounts(['app_code'=>'nano_banana','usage_unit_size'=>1000000,'platform_price'=>3,'tenant_price'=>5],1,1000000,true));
    }

    /** @dataProvider invalidInputs */
    public function testPreflightRejectsUnsupportedOrMalformedInputs(array $changes): void
    {
        $this->expectException(\Exception::class);
        Nano::prepareRequest($this->snapshot(),array_replace($this->request(),$changes));
    }

    public static function invalidInputs(): array
    {
        return [[['prompt'=>'']], [['prompt'=>['invalid']]], [['ratio'=>'2:7']], [['quality'=>'8K']],
            [['reference_images'=>array_fill(0,3,'https://fixtures.invalid/a.png')]],
            [['provider_params'=>['api_key'=>'do not forward']]], [['provider_params'=>['unknown'=>true]]]];
    }

    public function testOnlyMissingPreliminaryPromptCanBeDeferred(): void
    {
        $request=$this->request();unset($request['prompt']);
        self::assertArrayNotHasKey('prompt',Nano::prepareRequest($this->snapshot(),$request,'',0,true)->nativeInput);
        $this->expectExceptionMessage('请输入提示词');
        Nano::prepareRequest($this->snapshot(),$request);
    }

    public function testNewTokenSkuCannotSubmitWithoutAnUpfrontBudget(): void
    {
        $market=$this->market();$market['sku']['usage_unit']='token';
        $this->expectExceptionMessage('Token 费用上限');
        (new ReflectionMethod(Nano::class,'meterMarket'))->invoke(null,$market,$this->request(),0);
    }

    public function testApplicationJsonSchemaRatiosDoNotDependOnASiblingImageModel(): void
    {
        $ratios=(new ReflectionMethod(Nano::class,'ratioOptions'))->invoke(null,$this->market()['product'],'new-variant');
        self::assertContains('1:1',$ratios);self::assertContains('auto',$ratios);self::assertContains('21:9',$ratios);
    }
}
