<?php
namespace Tests\Feature;

use app\common\service\power\{MarketNanoBananaAppRuntimeService as Nano,MarketImageModelRuntimeService as Images,MarketGenerationMeter as Meter,MarketGenerationImageBilling as Billing,MarketPriceRuleService as Prices};
use app\common\service\app\aigc_image\AigcImageAssetService as Assets;
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic declarations and measured rows only; no supplier transport or valid output upload. */
class MarketNanoPixelBillingTest extends TestCase
{
    private static function declaration(): array{return ['version'=>3,'basis'=>'megapixel','rounding'=>'ceil_2','maximum_pixels_per_image'=>1000000,'maximum_images'=>4,'result_path'=>'data.image_urls','result_item_url'=>''];}
    private static function metadata(): array
    {
        $f=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($f['contracts']as$r)if(($r['app_code']??'')==='nano_banana')return $r;
        throw new \RuntimeException('Missing captured application declaration');
    }
    private static function market(): array
    {
        return ['product'=>['id'=>991801,'upstream_app_code'=>'nano_banana','upstream_api_code'=>'submit','source_payload'=>['market_metadata'=>self::metadata()]],
            'sku'=>['id'=>991802,'sku_key'=>'offline-nano-pixel','usage_unit'=>'business_unit','usage_unit_size'=>1,'upstream_price'=>3,'sale_points'=>3,'locked_params'=>['model'=>'nano-banana-2','resolution'=>'1K'],'source_payload'=>['meter_contract'=>self::declaration()]],
            'tenant_price'=>5,'model'=>'nano-banana-2','reference_limit'=>2,'ratio_options'=>['1:1','16:9']];
    }
    private static function request(): array{return ['prompt'=>'Synthetic Nano pixel fixture','ratio'=>'1:1','quality'=>'1K','quantity'=>2,'reference_images'=>[]];}
    public function testSkuGlobalMaximumIsFrozenSeparatelyFromUiQuantity(): void
    {
        $market=(new \ReflectionMethod(Nano::class,'meterMarket'))->invoke(null,self::market(),self::request(),0);
        $quote=new \ReflectionMethod(Nano::class,'quoteMarket');
        foreach([1,2]as$n){
            $q=$quote->invoke(null,$market,$n);self::assertSame(12.,$q['tenant_cost_points']);self::assertSame(20.,$q['user_charge_points']);
            self::assertSame($n,$q['quantity']);self::assertSame($n,$q['market_snapshot']['requested_image_quantity']);self::assertSame(4,$q['market_snapshot']['reserved_image_quantity']);self::assertSame('actual_usage',$q['settlement_mode']);
            $native=Nano::prepareRequest($q['market_snapshot'],self::request())->nativeInput;
            foreach(['maximum_images','maximum_pixels_per_image','requested_image_quantity','reserved_image_quantity']as$key)self::assertArrayNotHasKey($key,$native);
        }
    }
    public function testFixedApplicationRetailStillChargesActualReturnedSlots(): void
    {
        $market=(new \ReflectionMethod(Nano::class,'meterMarket'))->invoke(null,self::market(),self::request(),0);
        $market=(new \ReflectionMethod(Nano::class,'applyRetailBinding'))->invoke(null,$market,['user_unit_price'=>9,'tenant_cost_points'=>12],2,0);
        $q=(new \ReflectionMethod(Nano::class,'quoteMarket'))->invoke(null,$market,2);self::assertSame(36.,$q['user_charge_points']);self::assertSame(12.,$q['tenant_cost_points']);
        self::assertSame([.75,9.],Billing::amounts($q['market_snapshot'],1,.25));self::assertSame([1.5,18.],Billing::amounts($q['market_snapshot'],2,.5));
    }
    public function testSharedImageConsumersDoNotDownloadMissingOrExcessOutput(): void
    {
        $snapshot=['billing_meter'=>Meter::contract(self::market()['sku']),'reserved_image_quantity'=>1];
        foreach([Nano::class,Images::class]as$service){
            $images=new \ReflectionMethod($service,'images');self::assertSame([],$images->invoke(null,['status'=>'processing'],1,1,$snapshot));self::assertSame([],$images->invoke(null,['status'=>'failed'],1,1,$snapshot));
            try{$images->invoke(null,['status'=>'completed','data'=>['image_urls'=>['https://fixtures.invalid/a.png','https://fixtures.invalid/b.png']]],1,1,$snapshot);self::fail('Excess slots must reject before download');}
            catch(\InvalidArgumentException $e){self::assertStringContainsString('超过预占数量',$e->getMessage());}
        }
    }
    public function testOversizeImageBytesAreRejectedBeforeManagedStorage(): void
    {
        $chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',2,1,8,2,0,0,0)).$chunk('IDAT',gzcompress("\0\xff\0\0\xff\0\0")).$chunk('IEND','');
        $info=(new \ReflectionMethod(Assets::class,'verifiedImageInfo'))->invoke(null,$png,2);self::assertSame(2,$info[0]);self::assertSame(1,$info[1]);self::assertSame('image/png',$info['mime']);
        $this->expectExceptionMessage('实际图片像素超过冻结合同上限');Assets::persistGeneratedImage('data:image/jpeg;base64,'.base64_encode($png),1,1,true,1);
    }
    public function testHistoricalNanoPricesDoNotAcquireAPixelUpperBound(): void
    {
        $old=['app_code'=>'nano_banana','platform_price'=>3,'tenant_price'=>5,'usage_unit_size'=>7];self::assertSame([6.,10.],Billing::reservationAmounts($old,2));self::assertSame([3.,5.],Billing::amounts($old,1));
    }
    /** @dataProvider scenarios */
    public function testLocalRollbackGlobalBoundMeasuredSettlementRecoveryAndLegacyFreeze(string $scenario): void
    {
        if(getenv('AIGC_NANO_PIXEL_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded local fixture unavailable');
        $key='nano-pixel-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);
            $p=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline Nano pixel fixture','resource_type'=>'app_api','model_type'=>'image','source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_app_code'=>'nano_banana','upstream_api_code'=>'submit','upstream_model_code'=>'','upstream_channel_code'=>'','source_payload'=>['market_metadata'=>self::metadata(),'resource'=>['max_reference_images'=>2]],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $s=PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$key,'title'=>'Offline Nano pixel fixture','locked_params'=>['model'=>'nano-banana-2','resolution'=>'1K'],'usage_unit'=>'business_unit','usage_unit_size'=>1,'upstream_price'=>3,'sale_points'=>3,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>self::declaration()],'create_time'=>time(),'update_time'=>time()]);
            Prices::manual(0,(int)$p['id'],(int)$s['id'],3);Prices::manual(1,(int)$p['id'],(int)$s['id'],5);
            $override=[];if(str_starts_with($scenario,'fixed_')){Db::name('power_market_price_rule')->where(['owner_id'=>1,'scope_type'=>'global','scope_id'=>0])->update(['follow_apps'=>0]);$override=['user_unit_price'=>9,'tenant_cost_points'=>12];}
            $request=self::request()+['channel'=>'market_nano_banana:'.$p['id'].':'.base64_encode('nano-banana-2'),'market_sku_id'=>(int)$s['id']];
            $q=Nano::quote(1,$request,2,$override);$upper=$override?36.:20.;self::assertSame($upper,$q['user_charge_points']);self::assertSame(12.,$q['tenant_cost_points']);self::assertSame(4,$q['market_snapshot']['reserved_image_quantity']);self::assertSame(2,$q['market_snapshot']['requested_image_quantity']);
            $admission=Nano::openPlatformAdmissionQuote(1,$request,2);self::assertSame($q['market_sku_id'],$admission['quote']['market_sku_id']);self::assertSame(20.,$admission['quote']['user_charge_points']);
            $create=static fn()=>Nano::reserve(1,$user,'generate',$key,$request,$request,2,'aigc_image','aigc_image_task',$override);
            if(in_array($scenario,['user_insufficient','tenant_insufficient'],true)){
                if($scenario==='user_insufficient')Db::name('user')->where('id',$user)->update(['user_money'=>0]);else Db::name('tenant')->where('id',1)->update(['point_balance'=>0]);$low=$this->balances($user);
                try{$create();self::fail('The full global output bound must be funded');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}self::assertSame($low,$this->balances($user));self::assertSame(0,(int)AiConsumptionLog::where('product_id',$p['id'])->count());
            }else{
                $r=$create();self::assertSame($r['consumption_id'],$create()['consumption_id']);$id=(int)$r['consumption_id'];$log=AiConsumptionLog::find($id);$snapshot=$log['price_snapshot'];self::assertSame(4,$snapshot['reserved_image_quantity']);self::assertSame(round($before['user']-$upper,2),$this->balances($user)['user']);
                $native=Nano::prepareRequest($snapshot,$request)->nativeInput;self::assertSame($native,Nano::prepareRequest(json_decode(json_encode($snapshot),true),$request)->nativeInput);self::assertArrayNotHasKey('reserved_image_quantity',$native);
                $one=['image_uri'=>'https://fixtures.invalid/same.png','width'=>500,'height'=>500];$images=[$one,$one];if(in_array($scenario,['partial','fixed_partial'],true))$images=[$one];if($scenario==='extra_slots')$images=[$one,$one,$one];
                $settle=new \ReflectionMethod(Nano::class,'settle');
                if(in_array($scenario,['over_bound','unknown_dimensions','too_many_slots'],true)){
                    $bad=$images;if($scenario==='too_many_slots')$bad=array_fill(0,5,$one);else $bad[0]['width']=$scenario==='over_bound'?2001:0;
                    try{$settle->invoke(null,$id,$bad,'','',[]);self::fail('Invalid measurement must retain the reservation');}catch(\InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}
                    self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);self::assertSame(round($before['user']-$upper,2),$this->balances($user)['user']);
                }elseif(in_array($scenario,['accepted_unknown','invalid_receipt','immediate_failure'],true)){
                    $guard=$snapshot;$guard['app_code']='offline-no-transport';$log->save(['price_snapshot'=>$guard,'run_status'=>'running','create_time'=>time()-20000]);
                    if($scenario!=='accepted_unknown'){
                        $receipt=$scenario==='immediate_failure'?['status'=>'failed','message'=>'Synthetic failure']:['status'=>'completed','data'=>['image_urls'=>['data:image/png;base64,'.base64_encode('invalid image bytes')]]];
                        (new \ReflectionMethod(Nano::class,'retainPixelReceipt'))->invoke(null,$id,$receipt,'','offline-receipt');
                    }
                    $result=Nano::refresh($id);self::assertSame($scenario==='immediate_failure'?'failed':'running',$result['status']);self::assertSame([],$result['images']);self::assertSame($scenario==='immediate_failure'?'refunded':'reserved',AiConsumptionLog::find($id)['billing_status']);
                    self::assertSame($scenario==='immediate_failure'?$before['user']:round($before['user']-$upper,2),$this->balances($user)['user']);
                    if($scenario==='invalid_receipt')self::assertSame($receipt,AiConsumptionLog::find($id)['response_summary']['pixel_meter_receipt']);
                }elseif(in_array($scenario,['failure','cancel'],true)){
                    if($scenario==='cancel')Nano::cancel($id);else Nano::fail($id,'Synthetic failure');Nano::fail($id,'Duplicate failure');self::assertSame($before,$this->balances($user));
                }else{
                    $source=$s['source_payload'];$source['meter_contract']['maximum_pixels_per_image']=2000000;$source['meter_contract']['maximum_images']=8;$s->save(['source_payload'=>$source,'upstream_price'=>99,'sale_points'=>99]);
                    self::assertSame($snapshot,$create()['market_snapshot']);
                    if($scenario==='restart_ready'){
                        $guard=$snapshot;$guard['app_code']='offline-no-transport';$log->save(['price_snapshot'=>$guard,'create_time'=>time()-20000]);
                        (new \ReflectionMethod(Nano::class,'retainPixelReceipt'))->invoke(null,$id,['status'=>'completed'],'','offline-receipt');(new \ReflectionMethod(Nano::class,'retainPixelImages'))->invoke(null,$id,$images);
                        self::assertSame('success',Nano::refresh($id)['status']);
                    }else $settle->invoke(null,$id,$images,'','',[]);
                    $mp=count($images)*.25;$cost=count($images)*.75;$userCost=$override?count($images)*9.:count($images)*1.25;$done=$this->balances($user);
                    self::assertSame($mp,(float)AiConsumptionLog::find($id)['quantity']);self::assertSame(round($before['tenant']-$cost,2),$done['tenant']);self::assertSame(round($before['user']-$userCost,2),$done['user']);
                    $settle->invoke(null,$id,$images,'','',[]);Nano::fail($id,'Late failure');self::assertSame($done,$this->balances($user));
                    $guard=AiConsumptionLog::find($id)['price_snapshot'];$guard['app_code']='offline-no-transport';AiConsumptionLog::where('id',$id)->update(['price_snapshot'=>$guard]);$terminal=AiConsumptionLog::find($id)->toArray();
                    self::assertSame('success',Nano::refresh($id,true)['status']);(new \ReflectionMethod(Nano::class,'repairCompletedResult'))->invoke(null,$id,[$one]);self::assertSame($terminal,AiConsumptionLog::find($id)->toArray());self::assertSame($done,$this->balances($user));
                }
            }
        }finally{Db::rollback();}
        self::assertSame($before,$this->balances($user));self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function scenarios(): array{return [['success'],['partial'],['extra_slots'],['fixed_success'],['fixed_partial'],['over_bound'],['unknown_dimensions'],['too_many_slots'],['failure'],['cancel'],['user_insufficient'],['tenant_insufficient'],['restart_ready'],['invalid_receipt'],['accepted_unknown'],['immediate_failure']];}
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
