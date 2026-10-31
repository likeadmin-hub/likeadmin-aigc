<?php
namespace Tests\Feature;

use app\common\service\power\{MarketGenerationMeter as Meter,MarketGenerationPixelMeter as Pixel,MarketGenerationImageBilling as Billing,MarketImageModelRuntimeService as Images};
use app\common\service\app\aigc_image\AigcImageAssetService as Assets;
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic declarations and rollback ledgers. Never submit, poll or register supplier assets. */
class MarketPixelBillingTest extends TestCase
{
    public static function declaration(): array
    {
        return ['version'=>3,'basis'=>'megapixel','rounding'=>'ceil_2','maximum_pixels_per_image'=>1000000,'maximum_images'=>4,'result_path'=>'data.image_urls','result_item_url'=>''];
    }
    private static function meter(array $extra=[]): array{return Meter::contract(['usage_unit'=>'business_unit','meter_contract'=>array_replace(self::declaration(),$extra)]);}
    public function testBoundQuoteAndMeasuredSettlementUseDifferentPhysicalQuantities(): void
    {
        $meter=self::meter();$snapshot=['billing_meter'=>$meter,'usage_unit_size'=>1,'platform_price'=>10,'tenant_price'=>10];
        self::assertSame(20.,Billing::price(10.,2,$snapshot));
        $images=[['width'=>500,'height'=>500],['width'=>500,'height'=>500]];
        $mp=Pixel::measured($meter,$images,2);self::assertSame(.5,$mp);self::assertSame([5.,5.],Billing::amounts($snapshot,2,$mp));
        self::assertSame(0.01,Billing::price(.0001,1,$snapshot));
        self::assertSame(1.,Billing::price(.0001,1,['billing_meter'=>self::meter(['rounding'=>'ceil']),'usage_unit_size'=>1]));
        self::assertSame(['https://fixtures.invalid/same.png','https://fixtures.invalid/same.png'],Pixel::urls($meter,['data'=>['image_urls'=>['https://fixtures.invalid/same.png','https://fixtures.invalid/same.png']]]));
        self::assertSame($snapshot,json_decode(json_encode($snapshot),true));
    }
    /** @dataProvider invalidDeclarations */
    public function testInvalidBoundsCannotAcquirePixelPricing(array $extra): void
    {
        $this->expectException(\InvalidArgumentException::class);self::meter($extra);
    }
    public static function invalidDeclarations(): array
    {
        return [[['maximum_pixels_per_image'=>0]],[['maximum_pixels_per_image'=>'1000']],[['maximum_pixels_per_image'=>1000.5]],[['maximum_pixels_per_image'=>1000000001]],
            [['maximum_images'=>0]],[['maximum_images'=>17]],[['result_path'=>'**.images']],[['result_path'=>'data.width']],[['result_item_url'=>'metadata.url']],
            [['rounding'=>'half_up_6']],[['basis'=>'token']],[['unknown'=>true]]];
    }
    /** @dataProvider invalidMeasurements */
    public function testMissingAndOverBoundMeasuredOutputCannotCharge(array $images,int $reserved=1): void
    {
        $this->expectException(\InvalidArgumentException::class);Pixel::measured(self::meter(),$images,$reserved);
    }
    public static function invalidMeasurements(): array
    {
        return [[[]],[[['width'=>0,'height'=>1]]],[[['width'=>'500','height'=>500]]],[[['width'=>1001,'height'=>1000]]],
            [[['width'=>1,'height'=>1],['width'=>1,'height'=>1]]],[[['width'=>1,'height'=>1]],0],[[['width'=>65536,'height'=>1]]]];
    }
    public function testDeclaredResultPathIgnoresUnrelatedDimensionsAndChecksTransferStates(): void
    {
        $meter=self::meter(['result_path'=>'data.result.results','result_item_url'=>'url']);
        $result=['data'=>['result'=>['results'=>[['url'=>'https://fixtures.invalid/a.png','width'=>999999,'height'=>999999,'transfer_status'=>'success'],['url'=>'','transfer_status'=>'failed']]]]];
        self::assertSame(['https://fixtures.invalid/a.png'],Pixel::urls($meter,$result));
        $result['data']['result']['results'][1]['transfer_status']='pending';
        $this->expectExceptionMessage('尚未完成转存');Pixel::urls($meter,$result);
    }
    public function testRealImageByteInspectionPrecedesStorageAndDoesNotTrustExtensions(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/gX8AAAAASUVORK5CYII=');
        $info=(new \ReflectionMethod(Assets::class,'verifiedImageInfo'))->invoke(null,$png);
        self::assertSame(1,$info[0]);self::assertSame(1,$info[1]);self::assertSame('image/png',$info['mime']);
        $this->expectExceptionMessage('不是可核验的图片内容');Assets::persistGeneratedImage('data:image/png;base64,'.base64_encode('not image bytes'),1,1,true);
    }
    public function testUnboundedExistingPixelAndLegacyImagePricesRemainUnchanged(): void
    {
        self::assertSame([6.,6.],Billing::amounts(['platform_price'=>3,'tenant_price'=>3],2));
        $this->expectExceptionMessage('缺少可核验的像素报价');Meter::context(Meter::contract(['meter_summary'=>'百万像素，总点数向上取整','usage_unit'=>'business_unit']),['image_size'=>'square_hd']);
    }
    public function testPixelContractDoesNotOpenAnUnmeasuredApplicationConsumer(): void
    {
        $market=['sku'=>['source_payload'=>['meter_contract'=>self::declaration()],'usage_unit'=>'business_unit']];
        $this->expectExceptionMessage('该图片应用尚未接入实际像素回执结算');
        (new \ReflectionMethod(\app\common\service\power\MarketNanoBananaAppRuntimeService::class,'meterMarket'))->invoke(null,$market,[],1);
    }
    public function testTooManyDeclaredOutputsAreRejectedBeforeAnyStorage(): void
    {
        $this->expectExceptionMessage('实际图片数量超过预占数量');
        (new \ReflectionMethod(Images::class,'images'))->invoke(null,['status'=>'completed','data'=>['image_urls'=>['https://fixtures.invalid/a.png','https://fixtures.invalid/b.png']]],1,1,['billing_meter'=>self::meter(),'reserved_image_quantity'=>1]);
    }
    /** @dataProvider scenarios */
    public function testLocalRollbackPixelQuoteReserveSettlementAndRefund(string $scenario): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        $key='pixel-rollback-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);
            $meta=['params_schema'=>['prompt'=>['type'=>'string','required'=>true],'image_size'=>['type'=>'string','enum'=>['square']]],'max_reference_images'=>0,
                'material_capabilities'=>['modes'=>[['code'=>'text_to_image','method'=>'text_to_image','fields'=>[]]]]];
            $product=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline pixel fixture','resource_type'=>'model','model_type'=>'image','source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>'pixel-fixture','upstream_channel_code'=>'fixture','source_payload'=>['market_metadata'=>$meta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $sku=PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key,'title'=>'Offline pixel fixture','locked_params'=>['image_size'=>'square'],'usage_unit'=>'business_unit','usage_unit_size'=>1,'upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>self::declaration()],'create_time'=>time(),'update_time'=>time()]);
            $request=['model_id'=>'market_image_model:'.$product['id'],'market_sku_id'=>$sku['id'],'prompt'=>'Synthetic pixel fixture','generation_method'=>'text_to_image','quantity'=>2,'width'=>1,'height'=>1];
            $quote=Images::quote(1,$request,2);self::assertSame(20.,$quote['user_charge_points']);self::assertSame('actual_usage',$quote['settlement_mode']);
            $admission=Images::openPlatformAdmissionQuote(1,$request,2);self::assertSame($quote['market_sku_id'],$admission['quote']['market_sku_id']);self::assertSame($quote['user_charge_points'],$admission['quote']['user_charge_points']);
            if(in_array($scenario,['user_insufficient','tenant_insufficient'],true)){
                if($scenario==='user_insufficient')Db::name('user')->where('id',$user)->update(['user_money'=>0]);else Db::name('tenant')->where('id',1)->update(['point_balance'=>0]);$low=$this->balances($user);
                try{Images::reserve(1,$user,'generate',$key,$request,$request,2,'aigc_image','aigc_image_task');self::fail('Insufficient balance must block reservation');}catch(\RuntimeException$e){self::assertNotSame('',$e->getMessage());}self::assertSame($low,$this->balances($user));return;
            }
            $r=Images::reserve(1,$user,'generate',$key,$request,$request,2,'aigc_image','aigc_image_task');$id=(int)$r['consumption_id'];$log=AiConsumptionLog::find($id);$snapshot=$log['price_snapshot'];
            self::assertSame(2,$snapshot['reserved_image_quantity']);self::assertSame(round($before['user']-20,2),$this->balances($user)['user']);
            $native=Images::prepareRequest($snapshot,$request)->nativeInput;self::assertArrayNotHasKey('maximum_pixels_per_image',$native);self::assertSame($native,Images::prepareRequest(json_decode(json_encode($snapshot),true),$request)->nativeInput);
            $settle=new \ReflectionMethod(Images::class,'settle');$one=['image_uri'=>'https://fixtures.invalid/same.png','width'=>500,'height'=>500];$images=[$one,$one];
            if($scenario==='partial')$images=[$one];
            if($scenario==='immediate_failure'){
                (new \ReflectionMethod(Images::class,'retainPixelReceipt'))->invoke(null,$id,['status'=>'failed','message'=>'Offline failure'],'','offline-pixel-receipt');
                self::assertSame('failed',Images::refresh($id)['status']);self::assertSame($before,$this->balances($user));
                self::assertSame('failed',Images::refresh($id)['status']);self::assertSame($before,$this->balances($user));
                return;
            }
            if($scenario==='immediate_unmeasured'){
                $receipt=['status'=>'completed','request_id'=>'offline-pixel-receipt','data'=>['image_urls'=>['data:image/png;base64,'.base64_encode('invalid image bytes')]]];
                (new \ReflectionMethod(Images::class,'retainPixelReceipt'))->invoke(null,$id,$receipt,'','offline-pixel-receipt');
                $pending=Images::refresh($id);self::assertSame('running',$pending['status']);self::assertSame([],$pending['images']);
                self::assertSame($receipt,AiConsumptionLog::find($id)['response_summary']['pixel_meter_receipt']);self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);
                self::assertSame('offline-pixel-receipt',AiConsumptionLog::find($id)['upstream_request_id']);self::assertSame(round($before['user']-20,2),$this->balances($user)['user']);
            }
            if(in_array($scenario,['over_bound','unknown_dimensions'],true)){
                $bad=$images;$bad[0]['width']=$scenario==='over_bound'?2001:0;
                try{$settle->invoke(null,$id,$bad,'','',[]);self::fail('Invalid pixel receipt must retain reservation');}catch(\InvalidArgumentException$e){self::assertNotSame('',$e->getMessage());}
                self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);self::assertSame(round($before['user']-20,2),$this->balances($user)['user']);
                $pending=(new \ReflectionMethod(Images::class,'responseFromConsumption'))->invoke(null,AiConsumptionLog::find($id)->toArray());self::assertSame('running',$pending['status']);self::assertSame([],$pending['images']);
            }
            if(in_array($scenario,['failure','over_bound','unknown_dimensions','immediate_unmeasured'],true)){
                Images::fail($id,'Offline fixture');Images::fail($id,'Duplicate failure');self::assertSame($before,$this->balances($user));
            }else{
                $sku->save(['sale_points'=>99,'source_payload'=>['meter_contract'=>array_replace(self::declaration(),['maximum_pixels_per_image'=>2000000])]]);
                if($scenario==='restart_measured'){
                    (new \ReflectionMethod(Images::class,'retainPixelReceipt'))->invoke(null,$id,['status'=>'completed'],'','offline-pixel-receipt');
                    (new \ReflectionMethod(Images::class,'retainReadyImages'))->invoke(null,$id,$images);
                    $replayed=Images::refresh($id);self::assertSame('success',$replayed['status']);self::assertSame($images,$replayed['images']);
                    self::assertSame($replayed,Images::refresh($id));
                }else $settle->invoke(null,$id,$images,'','',['width'=>999999,'height'=>999999]);
                $expected=$scenario==='partial'?2.5:5.;$done=$this->balances($user);self::assertSame(round($before['user']-$expected,2),$done['user']);self::assertSame('settled',AiConsumptionLog::find($id)['billing_status']);self::assertSame($scenario==='partial'?.25:.5,(float)AiConsumptionLog::find($id)['quantity']);
                $settle->invoke(null,$id,$images,'','',[]);Images::fail($id,'Late failure');self::assertSame($done,$this->balances($user));
            }
        }finally{Db::rollback();}
        self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function scenarios(): array{return [['success'],['partial'],['over_bound'],['unknown_dimensions'],['failure'],['user_insufficient'],['tenant_insufficient'],['immediate_unmeasured'],['restart_measured'],['immediate_failure']];}
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
