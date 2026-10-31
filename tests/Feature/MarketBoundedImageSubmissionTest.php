<?php
namespace Tests\Feature;

use app\common\service\power\{MarketBoundedImageSubmission as Guard,MarketNanoBananaAppRuntimeService as Nano,MarketImageModelRuntimeService as Images,MarketGenerationMeter as Meter};
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\point\PointService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Ledger fixtures and simulated interruptions only; no provider request, query, upload or HTTP login. */
class MarketBoundedImageSubmissionTest extends TestCase
{
    private function fixture(string $kind,callable $check): void
    {
        if(getenv('AIGC_BOUNDED_SUBMISSION_DB_TESTS')!=='1')self::markTestSkipped('Explicit rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['localhost','127.0.0.1']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded local fixture unavailable');
        $key='bounded-submit-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);
            $pixel=str_ends_with($kind,'pixel');$nano=str_starts_with($kind,'nano');
            $meter=$pixel?Meter::contract(['usage_unit'=>'megapixel','source_payload'=>['meter_contract'=>['version'=>3,'basis'=>'megapixel','rounding'=>'ceil_2','maximum_pixels_per_image'=>1000000,'maximum_images'=>2,'result_path'=>'data.image_urls','result_item_url'=>'']]]):Meter::tokenContract(['usage_unit'=>'token','usage_unit_size'=>1000,'source_payload'=>['meter_contract'=>['version'=>2,'basis'=>'token','rounding'=>'ceil_2','maximum_quantity'=>1000,'usage_path'=>'data.usage.total_tokens']]]);
            $snapshot=['billing_meter'=>$meter,'usage_unit'=>$pixel?'megapixel':'token','usage_unit_size'=>$pixel?1:1000,'platform_price'=>$pixel?5:10,'tenant_price'=>$pixel?10:20,'reserved_image_quantity'=>2,'requested_image_quantity'=>2,'app_code'=>$nano?'nano_banana':'aigc_image','model_type'=>'offline-no-transport'];
            $task=AiAppTask::create(['task_no'=>$key,'tenant_id'=>1,'user_id'=>$user,'app_code'=>'aigc_image','action_code'=>'generate','business_table'=>'aigc_image_task','business_id'=>0,'parent_task_id'=>0,'status'=>'running','progress'=>10,'request_summary'=>[],'result_summary'=>[],'estimated_tenant_cost'=>10,'estimated_user_price'=>20,'actual_tenant_cost'=>0,'actual_user_price'=>0,'idempotency_key'=>$key,'create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
            $c=AiConsumptionLog::create(['consume_no'=>$key,'app_task_id'=>$task['id'],'tenant_id'=>1,'user_id'=>$user,'app_code'=>'aigc_image','action_code'=>'generate','resource_type'=>'model','product_id'=>0,'sku_id'=>0,'protocol'=>'image_generate','provider'=>'offline-fixture','quantity'=>$pixel?2:1000,'usage_unit'=>$snapshot['usage_unit'],'price_snapshot'=>$snapshot,'usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>[],'run_status'=>'reserved','billing_status'=>'reserved','reserved_tenant_cost'=>10,'reserved_user_price'=>20,'actual_tenant_cost'=>0,'actual_user_price'=>0,'tenant_point_sn'=>$key.'-reserve','user_point_sn'=>$key.'-reserve','upstream_task_id'=>'','upstream_request_id'=>'','error_code'=>'','error_message'=>'','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
            PointService::reserveBusinessAmountsInCurrentTransaction(1,$user,10,20,$key.'-reserve','Synthetic submission reserve',['consumption_id'=>$c['id'],'app_code'=>'aigc_image']);
            $check((int)$c['id'],$user,$snapshot,$nano?Nano::class:Images::class,$before);
        }finally{Db::rollback();}
        self::assertSame($before,$this->balances($user));self::assertSame(0,(int)AiConsumptionLog::where('consume_no',$key)->count());
    }
    /** @dataProvider nanoKinds */
    public function testCancelRechecksTheLockedSubmissionState(string $kind): void
    {
        $this->fixture($kind,function($id,$user,$snapshot,$runtime,$before){
            AiConsumptionLog::where('id',$id)->update(['run_status'=>'submitting']);$held=$this->balances($user);
            $blocked=false;try{$runtime::fail($id,'Synthetic stale cancel','canceled');}catch(\Exception $e){$blocked=true;self::assertStringContainsString('无法取消',$e->getMessage());}self::assertTrue($blocked,'A stale cancel must not refund an already claimed submission');
            self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);self::assertSame($held,$this->balances($user));
        });
    }
    public static function nanoKinds(): array{return [['nano_token'],['nano_pixel']];}
    /** @dataProvider scenarios */
    public function testClaimInterruptionReceiptTerminalAndReplayBoundaries(string $kind,string $scenario): void
    {
        $this->fixture($kind,function($id,$user,$snapshot,$runtime,$before)use($scenario){
            $held=$this->balances($user);$c=AiConsumptionLog::find($id);
            if($scenario==='cancel_before'){$runtime::cancel($id);self::assertFalse(Guard::begin($id));self::assertSame($before,$this->balances($user));return;}
            if($scenario==='legacy'){$s=$snapshot;unset($s['billing_meter']);$c->save(['price_snapshot'=>$s]);self::assertTrue(Guard::begin($id));self::assertSame('reserved',AiConsumptionLog::find($id)['run_status']);self::assertFalse(Guard::holdUnknown($id));return;}
            self::assertTrue(Guard::begin($id));self::assertSame('submitting',AiConsumptionLog::find($id)['run_status']);self::assertFalse(Guard::begin($id));
            self::assertSame('running',$runtime::submit($id,[])['status']);self::assertSame($held,$this->balances($user));
            if($scenario==='cancel_after'){
                try{$runtime::cancel($id);self::fail('An occupied submission cannot cancel');}catch(\Exception $e){self::assertStringContainsString('无法取消',$e->getMessage());}self::assertSame($held,$this->balances($user));
            }elseif($scenario==='terminal'){
                $images=[['image_uri'=>'stored-synthetic.png','width'=>500,'height'=>500]];$receipt=['data'=>['usage'=>['total_tokens'=>250]]];
                (new \ReflectionMethod($runtime,'settle'))->invoke(null,$id,$images,'','',$receipt);$done=AiConsumptionLog::find($id)->toArray();$paid=$this->balances($user);self::assertTrue(Guard::holdUnknown($id));self::assertFalse(Guard::begin($id));self::assertSame($done,AiConsumptionLog::find($id)->toArray());self::assertSame($paid,$this->balances($user));
            }else{
                if($scenario==='receipt'){$summary=['images'=>[['image_uri'=>'stored-synthetic.png','width'=>500,'height'=>500]],'pixel_meter_receipt'=>['status'=>'completed']];$c->save(['upstream_task_id'=>'offline-task','response_summary'=>$summary]);}
                if($scenario==='restart')$c->save(['create_time'=>time()-20000]);
                self::assertTrue(Guard::holdUnknown($id));$current=AiConsumptionLog::find($id);self::assertSame($scenario==='receipt'?'running':'pending_confirmation',$current['run_status']);self::assertSame('reserved',$current['billing_status']);self::assertSame($snapshot,$current['price_snapshot']);self::assertSame($held,$this->balances($user));self::assertFalse(Guard::begin($id));
                self::assertSame('running',$runtime::submit($id,[])['status']);
                if($scenario==='restart'){self::assertSame('running',$runtime::refresh($id)['status']);self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);}
                if($scenario==='receipt')self::assertSame($summary,$current['response_summary']);
            }
        });
    }
    public static function scenarios(): array{$r=[];foreach(['nano_token','nano_pixel','image_token','image_pixel']as$k)foreach(['unknown','receipt','restart','terminal','cancel_before','cancel_after','legacy']as$s)$r[]=[$k,$s];return $r;}
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
