<?php
namespace Tests\Feature;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketNanoBananaAppRuntimeService as Nano;
use app\common\service\power\MarketPriceRuleService as Prices;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\facade\Db;

/** Explicit local rollback fixtures. Never submits a valid request or stores an output asset. */
class MarketNanoBananaLifecycleTest extends TestCase
{
    /** @dataProvider lifecycles */
    public function testPreparedReservationSettlementRefundAndIdempotency(string $meter, string $outcome): void
    {
        if (getenv('AIGC_NANO_PREPARATION_DB_TESTS') !== '1') self::markTestSkipped('Explicit local rollback test only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->value('id');
        if (!$user) self::markTestSkipped('Funded local fixture unavailable');
        $key='phase4-nano-'.bin2hex(random_bytes(7));
        Db::startTrans();
        try {
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();
            $before=$this->state($user);
            $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
            foreach($fixture['contracts'] as $row)if(($row['app_code']??'')==='nano_banana'){$metadata=$row;break;}
            $product=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Local Nano rollback fixture',
                'resource_type'=>'app_api','model_type'=>'image','source_code'=>'offline-fixture','upstream_resource_key'=>$key,
                'upstream_app_code'=>'nano_banana','upstream_api_code'=>'submit','upstream_model_code'=>'','upstream_channel_code'=>'',
                'source_payload'=>['market_metadata'=>$metadata,'resource'=>['max_reference_images'=>2]],
                'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $source=match($meter){'image'=>['meter_contract'=>['version'=>1,'basis'=>'image','rounding'=>'ceil',
                    'reference_images'=>['free'=>1,'numerator'=>1,'denominator'=>2]]],
                'call'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']],default=>[]};
            $sku=PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key,'title'=>'Offline fixture',
                'locked_params'=>['model'=>'nano-banana-2','resolution'=>'1K'],'usage_unit'=>'per_call','usage_unit_size'=>1,
                'upstream_price'=>3,'sale_points'=>3,'sale_status'=>1,'status'=>1,'source_payload'=>$source,
                'create_time'=>time(),'update_time'=>time()]);
            Prices::manual(0,(int)$product['id'],(int)$sku['id'],3);Prices::manual(1,(int)$product['id'],(int)$sku['id'],5);
            $override=[];
            if($outcome==='retail_success'){
                Db::name('power_market_price_rule')->where(['owner_id'=>1,'scope_type'=>'global','scope_id'=>0])->update(['follow_apps'=>0]);
                $override=['user_unit_price'=>9,'tenant_cost_points'=>8];
            }
            $request=['channel'=>'market_nano_banana:'.$product['id'].':'.base64_encode('nano-banana-2'),
                'market_sku_id'=>(int)$sku['id'],'prompt'=>'Offline fixture','quality'=>'1K','ratio'=>'1:1','quantity'=>2,
                'reference_images'=>['https://fixtures.invalid/a.png','https://fixtures.invalid/b.png']];
            $quote=Nano::quote(1,$request,2,$override);
            $reserve=static fn($id)=>Nano::reserve(1,$user,'generate',$id,$request,$request,2,'aigc_image','aigc_image_task',$override);
            $reserved=$reserve($key);$duplicate=$reserve($key);
            self::assertSame($reserved['consumption_id'],$duplicate['consumption_id']);
            $log=AiConsumptionLog::find($reserved['consumption_id']);$snapshot=$log['price_snapshot'];
            // JSON persistence canonicalizes integral floats and the shared
            // consumption model adds the authoritative owner price-rule trace.
            self::assertEquals($quote['market_snapshot'],array_intersect_key($snapshot,$quote['market_snapshot']));
            self::assertSame('3.000000',$snapshot['pricing_rules']['platform']['price']);
            $expected=$meter==='image'?[8.0,13.0]:($meter==='call'?[3.0,5.0]:[6.0,10.0]);
            if($outcome==='retail_success')$expected=[8.0,18.0];
            self::assertSame($expected,[(float)$log['reserved_tenant_cost'],(float)$log['reserved_user_price']]);
            self::assertSame(round($before['user']-$expected[1],2),$this->state($user)['user']);
            self::assertSame(1,$this->state($user)['consumptions']-$before['consumptions']);
            Prices::manual(0,(int)$product['id'],(int)$sku['id'],30);Prices::manual(1,(int)$product['id'],(int)$sku['id'],50);
            if (in_array($outcome,['success','retail_success'],true)) {
                $settle=new ReflectionMethod(Nano::class,'settle');
                $complete=static fn()=>$settle->invoke(null,(int)$log['id'],[['url'=>'https://fixtures.invalid/result.png']],'fixture-request','fixture-task',[]);
                $complete();$settled=$this->state($user);$complete();self::assertSame($settled,$this->state($user));
                $cost=$meter==='image'?[5.0,8.0]:[3.0,5.0];
                if($outcome==='retail_success')$cost=[5.0,9.0];
                self::assertSame($cost,[(float)AiConsumptionLog::find($log['id'])['actual_tenant_cost'],(float)AiConsumptionLog::find($log['id'])['actual_user_price']]);
                self::assertSame(round($before['user']-$cost[1],2),$settled['user']);
                self::assertSame('settled',AiConsumptionLog::find($log['id'])['billing_status']);
                $repair=new ReflectionMethod(Nano::class,'repairCompletedResult');
                $images=[['url'=>'https://fixtures.invalid/result.png'],['url'=>'https://fixtures.invalid/extra.png']];
                $repair->invoke(null,(int)$log['id'],$images);$repair->invoke(null,(int)$log['id'],$images);
                self::assertSame($settled,$this->state($user),'Metadata repair must not back-charge a historical result');
                self::assertSame($cost,[(float)AiConsumptionLog::find($log['id'])['actual_tenant_cost'],(float)AiConsumptionLog::find($log['id'])['actual_user_price']]);
            } else {
                if ($outcome==='invalid_submit' || $outcome==='changed_material_submit') {
                    $invalid=$request;
                    if ($outcome==='invalid_submit') $invalid['prompt']='';
                    else $invalid['reference_images']=['https://fixtures.invalid/a.png'];
                    // Legacy fixed-price snapshots have no material-dependent fee to conflict.
                    if ($outcome==='changed_material_submit' && $meter==='legacy') $invalid['prompt']='';
                    $noTransport=$snapshot;$noTransport['app_code']='offline-no-transport';
                    $log->save(['price_snapshot'=>$noTransport]);
                    $expectedError=$invalid['prompt']===''?'请输入提示词':'提交素材与预占计费快照不一致';
                    try { Nano::submit((int)$log['id'],$invalid);self::fail('Preflight should reject before any provider call'); }
                    catch (\Exception $e) { self::assertStringContainsString($expectedError,$e->getMessage()); }
                    self::assertSame('request_invalid',AiConsumptionLog::find($log['id'])['error_code']);
                } elseif ($outcome==='cancel') Nano::cancel((int)$log['id']);
                else Nano::fail((int)$log['id'],'Offline supplier failure');
                $state=$this->state($user);Nano::fail((int)$log['id'],'Duplicate failure');self::assertSame($state,$this->state($user));
                self::assertSame($before['user'],$state['user']);self::assertSame($before['tenant'],$state['tenant']);
                self::assertSame('refunded',AiConsumptionLog::find($log['id'])['billing_status']);
                self::assertSame('',AiConsumptionLog::find($log['id'])['upstream_task_id']);
            }
        } finally { Db::rollback(); }
        self::assertSame($before,$this->state($user),'Fixtures, balances and point logs must all roll back');
    }

    public static function lifecycles(): array
    {
        $rows=[];
        foreach(['legacy','image','call'] as $meter)foreach(['success','fail','cancel','invalid_submit','changed_material_submit'] as $outcome){
            if($meter==='call'&&$outcome==='changed_material_submit')continue;
            $rows[]=[$meter,$outcome];
        }
        $rows[]=['image','retail_success'];
        return $rows;
    }

    private function state(int $user): array
    {
        return ['user'=>(float)Db::name('user')->where('id',$user)->value('user_money'),
            'tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),
            'tasks'=>(int)Db::name('ai_app_task')->count(),'consumptions'=>(int)Db::name('ai_consumption_log')->count(),
            'user_logs'=>(int)Db::name('user_account_log')->count(),'tenant_logs'=>(int)Db::name('tenant_point_log')->count()];
    }
}
