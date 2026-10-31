<?php
namespace Tests\Feature;

use app\common\model\ai\{AiConsumptionLog, AiTaskJob};
use app\common\service\ai\AiTaskJobService as Jobs;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Queue completion fences, no provider calls; every row is rolled back. */
class MarketJobLeaseCompletionTest extends TestCase
{
    /** @dataProvider completions */
    public function testOnlyTheRunningLeaseOwnerCanCompleteAndRecordAnEvent(string $type,string $method,string $state): void
    {
        if(getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS')!=='1')self::markTestSkipped('Explicit local queue fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        Db::startTrans();
        try{
            $c=AiConsumptionLog::create(['consume_no'=>'b54-'.bin2hex(random_bytes(8)),'tenant_id'=>199999954,'user_id'=>199999954,'app_code'=>'aigc_video','run_status'=>'success','billing_status'=>'settled','create_time'=>time(),'update_time'=>time()]);
            $job=AiTaskJob::create(['job_no'=>'b54-'.bin2hex(random_bytes(8)),'job_type'=>$type,'consumption_id'=>$c['id'],
                'status'=>in_array($state,['success','pending'],true)?$state:'running','attempts'=>2,'lease_token'=>$state==='blank'?'':'current',
                'lease_expire_time'=>time()+60,'last_error'=>'before','create_time'=>time(),'update_time'=>time()-60]);
            $before=$job->toArray();$caller=$before;if($state==='stale')$caller['lease_token']='previous';
            $call=static function()use($method,$caller){return match($method){'succeed'=>Jobs::succeed($caller),'retry'=>Jobs::retry($caller,new \RuntimeException('owned fixture failure')),default=>Jobs::reschedule($caller,5)};};
            $delay=$call();if($method==='reschedule')self::assertSame(5,$delay);
            $after=AiTaskJob::find($job['id'])->toArray();$events=Db::name('ai_consumption_event')->where('consumption_id',$c['id'])->select()->toArray();
            if($state==='active'){
                self::assertSame(['succeed'=>'success','retry'=>'retrying','reschedule'=>'pending'][$method],$after['status']);self::assertSame('',$after['lease_token']);self::assertSame(0,(int)$after['lease_expire_time']);
                self::assertCount(1,$events);self::assertSame(['succeed'=>'job_success','retry'=>'job_retry','reschedule'=>'job_waiting'][$method],$events[0]['event_type']);
                $call();self::assertSame($after,AiTaskJob::find($job['id'])->toArray());self::assertSame(1,(int)Db::name('ai_consumption_event')->where('consumption_id',$c['id'])->count());
            }else{self::assertSame($before,$after);self::assertSame([],$events);}
        }finally{Db::rollback();}
    }

    public static function completions(): array
    {
        $rows=[];foreach(['transfer_result','query_result','process_result']as$type)foreach(['succeed','retry','reschedule']as$method)foreach(['active','stale','success','pending','blank']as$state)$rows[]=[$type,$method,$state];return $rows;
    }
}
