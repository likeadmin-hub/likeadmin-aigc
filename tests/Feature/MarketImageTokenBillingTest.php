<?php
namespace Tests\Feature;

use app\common\service\power\{MarketImageModelRuntimeService as Images,MarketGenerationMeter as Meter,MarketGenerationImageBilling as Billing,MarketGenerationCompatibilityAudit as Audit,MarketPriceRuleService as Prices};
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic upper bounds and stored images only. Never submits, queries or downloads supplier output. */
class MarketImageTokenBillingTest extends TestCase
{
    private static function declaration(): array{return ['version'=>2,'basis'=>'token','rounding'=>'ceil_2','maximum_quantity'=>1000,'usage_path'=>'data.usage.total_tokens'];}
    private static function sku(): array{return ['usage_unit'=>'token','usage_unit_size'=>1000,'source_payload'=>['meter_contract'=>self::declaration()]];}
    public function testImageModelBudgetAndNativeBindingUseTheSharedContract(): void
    {
        $snapshot=['platform_price'=>10,'tenant_price'=>20,'usage_unit_size'=>1000,'billing_meter'=>Meter::tokenContract(self::sku())];
        self::assertSame(10.,Billing::price(10,2,$snapshot));self::assertSame([2.5,5.],Billing::amounts($snapshot,2,250));
        self::assertSame('recognized_upper_bound',Audit::sku('image','model',self::sku())['meter_status']);
        self::assertSame('recognized_upper_bound',Audit::sku('image','app_api',self::sku(),'nano_banana')['meter_status']);
        self::assertSame(2,\app\common\service\open_platform\OpenNativeMarketPricing::boundedTokenBinding(['resource_type'=>'model','model_type'=>'image'],self::sku())['version']);
    }
    public function testImageBudgetStillRequiresAFormalHardBound(): void
    {
        $this->expectExceptionMessage('Token 费用上限');
        (new \ReflectionMethod(Images::class,'meterMarket'))->invoke(null,['sku'=>['usage_unit'=>'token','usage_unit_size'=>1000]],[],false,0);
    }
    public function testUnsettledImagesRemainHiddenAndFailedReceiptsCannotDownload(): void
    {
        $s=['billing_meter'=>Meter::tokenContract(self::sku())];
        self::assertSame([],(new \ReflectionMethod(Images::class,'images'))->invoke(null,['status'=>'failed','images'=>['https://fixtures.invalid/unreachable.png']],1,1,$s));
        $c=['price_snapshot'=>$s,'billing_status'=>'reserved','run_status'=>'success','upstream_task_id'=>'','upstream_request_id'=>'','response_summary'=>['images'=>[['image_uri'=>'stored-fixture']]]];
        $r=(new \ReflectionMethod(Images::class,'responseFromConsumption'))->invoke(null,$c);self::assertSame('running',$r['status']);self::assertSame([],$r['images']);
    }
    /** @dataProvider scenarios */
    public function testLocalRollbackUpperBoundActualUsageRecoveryAndFrozenPrices(string $scenario): void
    {
        if(getenv('AIGC_IMAGE_TOKEN_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded local fixture unavailable');
        $key='image-token-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);
            $meta=['params_schema'=>['prompt'=>['type'=>'string','required'=>true]],'max_reference_images'=>0,'material_capabilities'=>['modes'=>[['code'=>'text_to_image','method'=>'text_to_image','fields'=>[]]]]];
            $p=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline image Token fixture','resource_type'=>'model','model_type'=>'image','source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>'offline-image-token','upstream_channel_code'=>'offline','source_payload'=>['market_metadata'=>$meta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $s=PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$key,'title'=>'Offline image Token fixture','locked_params'=>[],'usage_unit'=>'token','usage_unit_size'=>1000,'upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>self::declaration()],'create_time'=>time(),'update_time'=>time()]);
            Prices::manual(0,(int)$p['id'],(int)$s['id'],10);Prices::manual(1,(int)$p['id'],(int)$s['id'],20);
            $request=['model_id'=>'market_image_model:'.$p['id'],'market_sku_id'=>(int)$s['id'],'prompt'=>'Synthetic image Token fixture','generation_method'=>'text_to_image','quantity'=>2,'maximum_quantity'=>1,'token_budget'=>1];
            $q=Images::quote(1,$request,2);self::assertSame(2,$q['quantity']);self::assertSame(1000,$q['billing_quantity']);self::assertSame(10.,$q['tenant_cost_points']);self::assertSame(20.,$q['user_charge_points']);self::assertSame('actual_usage',$q['settlement_mode']);
            $admission=Images::openPlatformAdmissionQuote(1,$request,2);self::assertSame($q['market_sku_id'],$admission['quote']['market_sku_id']);self::assertSame(20.,$admission['quote']['user_charge_points']);
            $create=fn()=>Images::reserve(1,$user,'generate',$key,$request,$request,2,'aigc_image','aigc_image_task');
            if(in_array($scenario,['user_insufficient','tenant_insufficient'],true)){
                if($scenario==='user_insufficient')Db::name('user')->where('id',$user)->update(['user_money'=>0]);else Db::name('tenant')->where('id',1)->update(['point_balance'=>0]);$low=$this->balances($user);
                try{$create();self::fail('The complete upper bound must be funded');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}
                self::assertSame($low,$this->balances($user));self::assertSame(0,(int)AiConsumptionLog::where('product_id',$p['id'])->count());
            }elseif($scenario==='fixed_override'){
                Db::name('power_market_price_rule')->where(['owner_id'=>1,'scope_type'=>'global','scope_id'=>0])->update(['follow_apps'=>0]);
                try{Images::quote(1,$request,2,['user_unit_price'=>1]);self::fail('A fixed override cannot replace actual Token billing');}catch(\Exception $e){self::assertStringContainsString('按实际用量',$e->getMessage());}
                try{Images::reserve(1,$user,'generate',$key,$request,$request,2,'aigc_image','aigc_image_task',['user_unit_price'=>1]);self::fail('An override cannot bypass reservation');}catch(\Exception $e){self::assertStringContainsString('按实际用量',$e->getMessage());}self::assertSame($before,$this->balances($user));
            }else{
                $r=$create();$id=(int)$r['consumption_id'];$log=AiConsumptionLog::find($id);$snapshot=$log['price_snapshot'];
                self::assertSame(1000.,(float)$log['quantity']);self::assertSame(2,$snapshot['requested_image_quantity']);self::assertEquals($snapshot,$r['market_snapshot']);self::assertSame('reserved',$log['billing_status']);self::assertSame(round($before['user']-20,2),$this->balances($user)['user']);
                $native=Images::prepareRequest($snapshot,$request)->nativeInput;self::assertSame($native,Images::prepareRequest(json_decode(json_encode($snapshot),true),$request)->nativeInput);self::assertArrayNotHasKey('maximum_quantity',$native);self::assertArrayNotHasKey('token_budget',$native);
                $one=['image_uri'=>'stored-synthetic.png','width'=>100,'height'=>100];$images=$scenario==='partial'?[$one]:[$one,$one];$receipt=['status'=>'completed','data'=>['usage'=>['total_tokens'=>250]]];$settle=new \ReflectionMethod(Images::class,'settle');
                $source=$s['source_payload'];$source['meter_contract']['maximum_quantity']=2000;$s->save(['source_payload'=>$source,'sale_points'=>99]);self::assertSame($snapshot,AiConsumptionLog::find($id)['price_snapshot']);
                if(in_array($scenario,['failure','cancel'],true)){
                    if($scenario==='cancel')Images::cancel($id);else Images::fail($id,'Synthetic failure');Images::fail($id,'Duplicate failure');self::assertSame($before,$this->balances($user));
                }elseif($scenario==='preflight_unfunded'){
                    $guard=$snapshot;$guard['model_type']='offline-no-transport';$log->save(['price_snapshot'=>$guard,'reserved_tenant_cost'=>0,'reserved_user_price'=>0]);
                    try{Images::submit($id,$request);self::fail('Underfunded frozen bound cannot reach transport');}catch(\Exception $e){self::assertStringContainsString('预占未覆盖',$e->getMessage());}self::assertSame('refunded',AiConsumptionLog::find($id)['billing_status']);
                }else{
                    if(in_array($scenario,['missing','zero','wrong_path','invalid','over_bound','restart_ready','immediate_failure','accepted_unknown'],true)){
                        if($scenario==='missing')$receipt=['status'=>'completed'];if($scenario==='zero')$receipt['data']['usage']['total_tokens']=0;if($scenario==='invalid')$receipt['data']['usage']['total_tokens']='1e2';if($scenario==='wrong_path')$receipt=['status'=>'completed','usage'=>['total_tokens'=>250]];if($scenario==='over_bound')$receipt['data']['usage']['total_tokens']=1001;if($scenario==='immediate_failure')$receipt=['status'=>'failed'];
                        // A broken recovery path must stop at adapter selection, never reach the supplier.
                        $guard=$snapshot;$guard['model_type']='offline-no-transport';$log->save(['price_snapshot'=>$guard,'run_status'=>'running','create_time'=>time()-20000]);
                        if($scenario!=='accepted_unknown'){
                            (new \ReflectionMethod(Images::class,'retainMeterReceipt'))->invoke(null,$id,$receipt,'','offline-receipt');
                            (new \ReflectionMethod(Images::class,'retainReadyImages'))->invoke(null,$id,$images);
                        }
                        $result=Images::refresh($id);$expected=$scenario==='immediate_failure'?'failed':($scenario==='restart_ready'?'success':'running');self::assertSame($expected,$result['status']);
                        if($expected==='running'){self::assertSame([],$result['images']);self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);self::assertSame(round($before['user']-20,2),$this->balances($user)['user']);
                            // Later authoritative usage may complete the same local record without resubmission.
                            $receipt=['status'=>'completed','data'=>['usage'=>['total_tokens'=>250]]];$settle->invoke(null,$id,$images,'','',$receipt);
                        }elseif($expected==='failed'){self::assertSame($before,$this->balances($user));}
                    }else $settle->invoke(null,$id,$images,'','',$receipt);
                    if($scenario!=='immediate_failure'){
                        $done=$this->balances($user);self::assertSame(round($before['tenant']-2.5,2),$done['tenant']);self::assertSame(round($before['user']-5,2),$done['user']);self::assertSame(250.,(float)AiConsumptionLog::find($id)['quantity']);self::assertSame('settled',AiConsumptionLog::find($id)['billing_status']);
                        $settle->invoke(null,$id,$images,'','',$receipt);Images::fail($id,'Late failure');self::assertSame($done,$this->balances($user));self::assertSame('success',Images::refresh($id)['status']);
                    }
                }
            }
        }finally{Db::rollback();}
        self::assertSame($before,$this->balances($user));self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function scenarios(): array{return array_map(static fn($s)=>[$s],['success','partial','missing','zero','wrong_path','invalid','over_bound','restart_ready','immediate_failure','accepted_unknown','failure','cancel','user_insufficient','tenant_insufficient','preflight_unfunded','fixed_override']);}
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
