<?php
namespace Tests\Feature;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__.'/MarketNestedVideoDurationTest.php';

/** Opt-in local rollback only. No submit, media probe, upload or supplier request. */
class MarketMixedVideoLifecycleTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testMixedVideoQuoteReservationFrozenRequestAndRefund(string $scenario): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');
        if(!$user)self::markTestSkipped('Funded rollback fixture unavailable');
        $bundle=MarketNestedVideoDurationTest::bundle(['type'=>'integer','enum'=>[-1,4,8]]);
        $catalog=Review::reviewedCatalogue($bundle,'Synthetic mixed-media validator fixture');
        $path=tempnam(sys_get_temp_dir(),'mixed-contract-');
        file_put_contents($path,json_encode($catalog,JSON_THROW_ON_ERROR));
        $previous=getenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH');
        putenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$path);
        $key='mixed-rollback-'.bin2hex(random_bytes(8));
        Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();
            $before=$this->balances($user);
            $meta=$bundle['entries'][0]['binding_declaration']+['upstream_metadata'=>['model_code'=>'declared-wan-fixture','channel_code'=>'fixture-channel']];
            $product=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline mixed video fixture','resource_type'=>'model','model_type'=>'video',
                'source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>'declared-wan-fixture','upstream_channel_code'=>'fixture-channel',
                'source_payload'=>['market_metadata'=>$meta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $skus=[];foreach([false,true]as$video){
                $locked=['resolution'=>'720P','_billing_video_input'=>$video];if($scenario==='locked')$locked['duration']=4;
                $skus[(int)$video]=PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key.(int)$video,'title'=>'Offline fixture',
                    'locked_params'=>$locked,'usage_unit'=>'output_second','usage_unit_size'=>1,'upstream_price'=>2,'sale_points'=>2,'sale_status'=>1,'status'=>1,
                    'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'output','rounding'=>'ceil']],'create_time'=>time(),'update_time'=>time()]);
            }
            $request=MarketMixedMaterialSelectorsTest::request()+['resource_type'=>'model','model_id'=>'market_video_model:'.$product['id'],
                'market_sku_id'=>$skus[0]['id']];
            if($scenario==='duplicate_slots'){
                $request['reference_assets'][1]['reference_slot_id']='video:a';
                $request['reference_assets'][]=array_replace($request['reference_assets'][1],['reference_slot_id'=>'video:b']);
            }
            if($scenario==='smart'){$request['material_mode']='text';$request['reference_assets']=[];}
            $request['duration']=$scenario==='configurable'?3:($scenario==='duplicate_slots'?4:-1);
            $normalized=Videos::normalizeDurationSelection(1,$request,(int)$request['duration']);
            self::assertSame($scenario==='smart'?-1:4,$normalized['duration']);
            self::assertSame((int)$skus[$scenario==='smart'?0:1]['id'],$normalized['market_sku_id']);
            $fractional=array_replace($request,['duration'=>4.5]);
            foreach([
                static fn()=>Videos::quote(1,$fractional),
                static fn()=>Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key.'-fraction',array_replace($request,$normalized),$fractional),
            ]as$reject){
                try{$reject();self::fail('Fractional duration must not be truncated before billing');}
                catch(\Exception$e){self::assertStringContainsString('整数',$e->getMessage());}
            }
            self::assertSame($before,$this->balances($user));
            $quote=Videos::quote(1,$request);
            $reserve=fn()=>Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key,$request,$request);
            $reserved=$reserve();self::assertSame($reserved['consumption_id'],$reserve()['consumption_id']);
            $log=AiConsumptionLog::find($reserved['consumption_id']);$snapshot=$log['price_snapshot'];
            self::assertArrayHasKey(Reviewed::PIN,$snapshot['market_metadata']);
            self::assertSame((float)$quote['user_charge_points'],(float)$log['reserved_user_price']);
            self::assertSame((float)$quote['tenant_cost_points'],(float)$log['reserved_tenant_cost']);
            self::assertSame($scenario==='smart'?8.0:4.0,(float)$log['quantity']);
            self::assertSame($scenario==='smart'?16.0:8.0,(float)$log['reserved_user_price']);
            self::assertSame('',(string)$log['upstream_task_id']);
            $prepared=Videos::prepareRequest($snapshot,array_replace($request,['duration'=>$normalized['duration']]));
            self::assertSame($normalized['duration'],$prepared->nativeInput['parameters']['duration']);
            self::assertSame($prepared->meterContext,$snapshot['meter_context']);
            if($scenario==='duplicate_slots'){
                $inputs=\app\common\service\power\MarketGenerationMediaInputs::extract($prepared->nativeInput);
                self::assertCount(2,$inputs['video']);
                self::assertSame($inputs['video'][0],$inputs['video'][1]);
                self::assertSame(['video:a','video:b'],array_values(array_filter(array_column($prepared->requestInput['reference_assets'],'reference_slot_id'))));
            }
            if($scenario==='smart')self::assertTrue($snapshot['requires_actual_duration']);
            self::assertSame(round($before['user']-(float)$log['reserved_user_price'],2),$this->balances($user)['user']);
            Videos::fail((int)$log['id'],'Offline fixture');Videos::fail((int)$log['id'],'Duplicate failure');
            self::assertSame($before,$this->balances($user));
            self::assertSame('refunded',AiConsumptionLog::find($log['id'])['billing_status']);
            self::assertSame(1,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$log['id'],'event_type'=>'refund'])->count());
        }finally{
            Db::rollback();putenv($previous===false?'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH':'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$previous);unlink($path);
        }
        self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function scenarios(): array{return [['configurable'],['smart'],['locked'],['duplicate_slots']];}
    private function balances(int $user): array
    {
        return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];
    }
}
