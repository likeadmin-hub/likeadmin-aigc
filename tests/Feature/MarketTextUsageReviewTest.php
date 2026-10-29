<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\power\MarketTextUsageReview;
final class MarketTextUsageReviewTest extends TestCase
{
    private function closureFixture():array
    {
        $key='close'.bin2hex(random_bytes(10));$tenant=1999999759;$user=7;
        $t=AiAppTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'script_plan','status'=>'success','idempotency_key'=>$key]);
        $c=AiConsumptionLog::create(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$t['id'],'consume_no'=>$key,'app_code'=>'aigc_short_drama','model_code'=>'fixture',
            'run_status'=>'success','billing_status'=>'settlement_failed','error_code'=>'provider_usage_missing','upstream_request_id'=>'chatcmpl-fixture','usage_snapshot'=>['provider_reported'=>false],
            'response_summary'=>['output_length'=>99],'actual_user_price'=>0,'actual_tenant_cost'=>0,'reserved_user_price'=>0,'reserved_tenant_cost'=>0,'create_time'=>time()]);
        $input=['system_prompt'=>'系统','content'=>'原文','model_selection'=>['model_code'=>'fixture'],'model_config'=>['max_tokens'=>100,'enable_thinking'=>false]];
        $unit=Db::name('aigc_short_drama_planning_unit')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$key,'unit_key'=>'fixture','status'=>'received',
            'request_json'=>json_encode($input),'result_json'=>json_encode(['result'=>['consumption_id'=>(int)$c['id'],'provider_request_id'=>'chatcmpl-fixture']])]);
        $wire=['model'=>'fixture','stream'=>true,'messages'=>[['role'=>'system','content'=>'系统'],['role'=>'user','content'=>'原文']],
            'max_tokens'=>100,'enable_thinking'=>false,'stream_options'=>['include_usage'=>true]];
        $source=\app\common\service\update\UpdateSourceClient::getSource();
        $identity=['api_key_id'=>1040,'tenant_id'=>18,'user_id'=>24,'key_sha256'=>hash('sha256',$source['active_api_key'])];
        $task=['task_id'=>'upstream-fixture','tenant_id'=>18,'user_id'=>24,'api_key_id'=>1040,'model_code'=>'fixture','status'=>3,'actual_points'=>0,'frozen_points'=>2,
            'pricing_detail'=>['_billing_prepaid'=>2,'_billing_tenant_prepaid'=>1],'create_time'=>(int)$c->getData('create_time'),'error_code'=>'curl_error','request_params'=>$wire];
        $proof=['source_identity'=>$identity,'candidates'=>[$task],'task'=>$task,'factory'=>['task_id'=>'upstream-fixture','tenant_id'=>18,'user_id'=>24,'api_key_id'=>1040,'model_code'=>'fixture','status'=>'failed','actual_points'=>0,'frozen_points'=>2],
            'user_logs'=>[['id'=>1,'task_id'=>'upstream-fixture','tenant_id'=>18,'user_id'=>24,'action'=>'freeze','points'=>-2],['id'=>2,'task_id'=>'upstream-fixture','tenant_id'=>18,'user_id'=>24,'action'=>'unfreeze','points'=>2]],
            'tenant_logs'=>[['id'=>1,'task_id'=>'upstream-fixture','tenant_id'=>18,'action'=>2,'points'=>1],['id'=>2,'task_id'=>'upstream-fixture','tenant_id'=>18,'action'=>4,'points'=>1]]];
        return [$c,$unit,$proof,$t];
    }

    public function testExactFailedRequestClosesOnceWithoutChangingOutputOrMissingUsage():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            [$c,$unit,$proof,$task]=$this->closureFixture();$id=(int)$c['id'];
            $preview=MarketTextUsageReview::resolveFailedPlanning(1999999759,7,$id,$unit,$proof);
            self::assertSame('refunded',$preview['disposition']);self::assertSame('settlement_failed',AiConsumptionLog::find($id)['billing_status']);
            foreach([1,2]as$n)MarketTextUsageReview::resolveFailedPlanning(1999999759,7,$id,$unit,$proof,true);
            $saved=AiConsumptionLog::find($id);self::assertSame('refunded',$saved['billing_status']);self::assertSame('failed',$saved['run_status']);
            self::assertSame(['provider_reported'=>false],$saved['usage_snapshot']);self::assertSame(99,$saved['response_summary']['output_length']);
            self::assertSame(1,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$id,'event_type'=>'usage_resolved'])->count());
            self::assertSame('failed',AiAppTask::find($task['id'])['status']);
        }finally{Db::rollback();}
    }

    /** @dataProvider invalidProofs */
    public function testClosureRejectsUnprovenFailure(string $case):void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            [$c,$unit,$p]=$this->closureFixture();
            if($case==='content')$p['task']['request_params']['messages'][1]['content']='其他请求';
            if($case==='charged')$p['factory']['actual_points']=1;
            if($case==='refund')$p['user_logs'][1]['points']=1;
            if($case==='owner')$p['factory']['user_id']=99;
            if($case==='localcharge')$c->save(['actual_user_price'=>1]);
            if($case==='invalidzero')$p['factory']['actual_points']='invalid';
            if($case==='negativelog'){$p['tenant_logs'][0]['points']=-1;$p['tenant_logs'][1]['points']=-1;}
            if($case==='wrongtime')$p['task']['create_time']+=3600;
            if($case==='ambiguous'){$other=$p['task'];$other['task_id']='another-identical-call';$p['candidates'][]=$other;}
            if($case==='wrongtask')$c->save(['upstream_task_id'=>'different-upstream-call']);
            if($case==='zeroSubset')foreach($p['user_logs']as&$log)$log['points']=0;
            if($case==='duplicatedLogs'){$p['user_logs'][]=$p['user_logs'][0];$p['user_logs'][]=$p['user_logs'][1];$p['task']['frozen_points']=4;$p['factory']['frozen_points']=4;$p['task']['pricing_detail']['_billing_prepaid']=4;}
            $this->expectException(\RuntimeException::class);MarketTextUsageReview::resolveFailedPlanning(1999999759,7,(int)$c['id'],$unit,$p,true);
        }finally{Db::rollback();}
    }
    public static function invalidProofs():array{return array_map(static fn($v)=>[$v],['content','charged','refund','owner','localcharge','invalidzero','negativelog','wrongtime','ambiguous','wrongtask','zeroSubset','duplicatedLogs']);}

    public function testDefinitiveNativeBalanceRejectionClosesOnceAndNeverInventsUsage():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            [$c]=$this->closureFixture();$c->save(['response_summary'=>['output_length'=>0]]);
            $result=['content'=>'','usage'=>[],'provider_request_id'=>'chatcmpl-fixture',
                'protocol_evidence'=>['branch'=>'native_responses','terminal_seen'=>true,'terminal_status'=>'failed','output_present'=>false,'legacy_output_event_count'=>0],
                'protocol_diagnostic'=>['last_event'=>'response.failed','provider_code'=>'INSUFFICIENT_BALANCE','response_status'=>'failed','output_present'=>false,
                    'output_items'=>0,'visible_chars'=>0,'reasoning_chars'=>0,'delta_count'=>0,'reasoning_delta_count'=>0,'function_delta_count'=>0]];
            MarketTextUsageReview::resolveNativeRejection(1999999759,7,(int)$c['id'],$result);
            self::assertSame('settlement_failed',AiConsumptionLog::find($c['id'])['billing_status']);
            MarketTextUsageReview::resolveNativeRejection(1999999759,7,(int)$c['id'],$result,true);
            self::assertTrue(MarketTextUsageReview::resolveNativeRejection(1999999759,7,(int)$c['id'],$result,true)['already_closed']);
            self::assertSame('refunded',AiConsumptionLog::find($c['id'])['billing_status']);self::assertSame(['provider_reported'=>false],AiConsumptionLog::find($c['id'])['usage_snapshot']);
            self::assertSame(1,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$c['id'],'event_type'=>'usage_resolved'])->count());
        }finally{Db::rollback();}
    }

    public function testMissingUsageReviewPreservesDeliveredOutputAndNeverInventsACharge():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            $key='review'.bin2hex(random_bytes(10));$t=AiAppTask::create(['tenant_id'=>1999999759,'user_id'=>7,'task_no'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'script_plan','status'=>'success','idempotency_key'=>$key]);
            $c=AiConsumptionLog::create(['tenant_id'=>1999999759,'user_id'=>7,'app_task_id'=>$t['id'],'consume_no'=>$key,'app_code'=>'aigc_short_drama','run_status'=>'success','billing_status'=>'pending_usage','upstream_request_id'=>'provider-fixture',
                'usage_snapshot'=>['provider_reported'=>false],'response_summary'=>['output_length'=>99],'actual_user_price'=>0,'actual_tenant_cost'=>0]);
            foreach([1,2]as$attempt)MarketTextUsageReview::flag(1999999759,7,(int)$c['id'],'provider-fixture',['candidate_only'=>true]);
            $saved=AiConsumptionLog::find($c['id']);self::assertSame('success',$saved['run_status']);self::assertSame('settlement_failed',$saved['billing_status']);self::assertSame(['provider_reported'=>false],$saved['usage_snapshot']);
            self::assertSame(99,$saved['response_summary']['output_length']);self::assertEquals(0,$saved['actual_user_price']);
            self::assertSame(1,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$c['id'],'event_type'=>'usage_review'])->count());
            self::assertSame('success',AiAppTask::find($t['id'])['status']);
        }finally{Db::rollback();}
    }
}
