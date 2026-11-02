<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\app\aigc_short_drama\ShortDramaTaskBilling as Billing;
final class ShortDramaTaskBillingTest extends TestCase
{
    public function testEveryPaidRepairCountsOnceAndAResolvedFailureStopsWaiting():void
    {
        if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            $task='billing'.bin2hex(random_bytes(10));$owner=['tenant_id'=>2000000719,'user_id'=>7];
            Db::name('aigc_short_drama_script_task')->insert($owner+['task_id'=>$task,'status'=>'success']);
            $ids=[];foreach([['settled',2],['settled',3],['refunded',0]]as[$state,$amount]){
                $key=bin2hex(random_bytes(12));$a=AiAppTask::create($owner+['task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama']);
                $c=AiConsumptionLog::create($owner+['app_task_id'=>$a['id'],'consume_no'=>$key,'app_code'=>'aigc_short_drama','billing_status'=>$state,
                    'actual_user_price'=>$amount,'actual_tenant_cost'=>$amount,'usage_snapshot'=>['prompt_tokens'=>10,'completion_tokens'=>2,'provider_reported'=>true]]);$ids[]=(int)$c['id'];
            }
            foreach([$ids[0],$ids[1],$ids[2],$ids[0]]as$i=>$id)Db::name('aigc_short_drama_planning_unit')->insert($owner+['task_id'=>$task,'unit_key'=>'call'.$i,
                'status'=>'received','error'=>$i===0?'validation:fixed later':'','result_json'=>json_encode(['result'=>['consumption_id'=>$id]])]);
            $r=Billing::resolve(2000000719,7,$task);self::assertSame(5.0,$r['user_charge_points']);self::assertSame(5.0,$r['tenant_cost_points']);self::assertSame('settled',$r['billing_status']);
            self::assertSame(3,$r['consumption_count']);self::assertSame(20,$r['prompt_tokens']);
            AiConsumptionLog::where('id',$ids[2])->update(['billing_status'=>'pending_usage']);self::assertSame('pending_usage',Billing::resolve(2000000719,7,$task)['billing_status']);
            self::assertNull(Billing::resolve(2000000720,7,$task));
        }finally{Db::rollback();}
    }
}
