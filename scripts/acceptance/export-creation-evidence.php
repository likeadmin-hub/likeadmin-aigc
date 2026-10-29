<?php
declare(strict_types=1);
// Read-only local acceptance evidence. Never reads provider credentials or executes/reconciles a task.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiConsumptionEvent};
$options=getopt('',['tenant:','user:','projects:','agent-runs:','drama-runs:','media-tasks:','out:']);
$tenant=(int)($options['tenant']??0);$user=(int)($options['user']??0);$out=$options['out']??'';
if($tenant<=0||$user<=0||!$out||config('database.connections.mysql.hostname')!=='127.0.0.1')throw new RuntimeException('Require explicit local tenant/user/output');
$ids=static fn(string $key):array=>array_values(array_unique(array_filter(array_map('intval',explode(',',$options[$key]??'')),static fn($id)=>$id>0)));
$decode=static fn($value):array=>is_array($value)?$value:(json_decode((string)$value,true)?:[]);
$owner=['tenant_id'=>$tenant,'user_id'=>$user];$projects=$ids('projects');$agents=$ids('agent-runs');$drama=$ids('drama-runs');
$mediaIds=array_values(array_filter(explode(',',$options['media-tasks']??'')));
foreach($mediaIds as$id)if(!preg_match('/^sd_gen_[A-Za-z0-9_]+$/D',$id))throw new RuntimeException('Invalid media task');
Db::startTrans();
try{
    $episodes=$projects?Db::name('aigc_short_drama_episode_task')->where($owner)->whereIn('project_id',$projects)->field('id,project_id,production_project_id,task_id,episode_number,status,completed_once,error,continuity_json')->order('project_id,episode_number')->select()->toArray():[];
    $allProjects=array_values(array_unique(array_merge($projects,array_filter(array_column($episodes,'production_project_id')))));
    $scripts=$allProjects?Db::name('aigc_short_drama_script_task')->where($owner)->whereIn('project_id',$allProjects)->field('task_id,app_task_id,project_id,status,error,user_charge_points,tenant_cost_points,request_json,result_json,create_time,finished_at')->order('id')->select()->toArray():[];
    $units=$scripts?Db::name('aigc_short_drama_planning_unit')->where($owner)->whereIn('task_id',array_column($scripts,'task_id'))->field('id,task_id,unit_key,status,attempt,result_json')->order('id')->select()->toArray():[];
    $media=$mediaIds?Db::name('aigc_short_drama_generation_task')->where($owner)->whereIn('task_id',$mediaIds)->field('id,task_id,project_id,source_task_id,task_type,app_task_id,consumption_id,market_product_id,market_sku_id,status,billing_status,user_charge_points,tenant_cost_points,provider_task_id,output_asset_ids,error_msg')->select()->toArray():[];
    $appIds=array_merge(array_column($scripts,'app_task_id'),array_column($media,'app_task_id'));
    if($agents)$appIds=array_merge($appIds,AiAppTask::where($owner)->where('business_table','aigc_llm_agent_run')->whereIn('business_id',$agents)->column('id'));
    if($drama)$appIds=array_merge($appIds,AiAppTask::where($owner)->where('business_table','aigc_short_drama_canvas_agent_run')->whereIn('business_id',$drama)->column('id'));
    $appIds=array_values(array_unique(array_filter($appIds)));
    $receiptIds=array_column($media,'consumption_id');
    foreach($units as$unit){$receipt=$decode($unit['result_json']);$receiptIds[]=(int)($receipt['result']['consumption_id']??0);}
    $receiptIds=array_values(array_unique(array_filter($receiptIds)));
    $consumptionIds=$appIds?AiConsumptionLog::where($owner)->whereIn('app_task_id',$appIds)->column('id'):[];
    $consumptionIds=array_values(array_unique(array_merge($consumptionIds,$receiptIds)));
    $consumptions=$consumptionIds?AiConsumptionLog::where($owner)->whereIn('id',$consumptionIds)->field('id,consume_no,app_task_id,app_code,action_code,product_id,sku_id,model_code,api_code,protocol,provider,upstream_request_id,upstream_task_id,run_status,billing_status,reserved_user_price,reserved_tenant_cost,actual_user_price,actual_tenant_cost,user_point_sn,tenant_point_sn,price_snapshot,usage_snapshot,response_summary,error_code,error_message,create_time,finish_time')->order('id')->select()->toArray():[];
    $appIds=array_values(array_unique(array_filter(array_merge($appIds,array_column($consumptions,'app_task_id')))));
    $tasks=$appIds?AiAppTask::where($owner)->whereIn('id',$appIds)->field('id,app_code,business_table,business_id,status,actual_user_price,actual_tenant_cost')->select()->toArray():[];
    $sn=[];foreach($consumptions as$c){foreach(['','-reserve','-refund','-settle','-release']as$suffix)$sn[]=$c['consume_no'].$suffix;$sn[]=$c['user_point_sn'];$sn[]=$c['tenant_point_sn'];}$sn=array_values(array_unique(array_filter($sn)));
    $userLogs=$sn?Db::name('user_account_log')->where($owner)->whereIn('source_sn',$sn)->field('id,user_id,tenant_id,source_sn,action,change_type,change_amount,extra')->order('id')->select()->toArray():[];
    $tenantLogs=$sn?Db::name('tenant_point_log')->where('tenant_id',$tenant)->whereIn('source_sn',$sn)->field('id,tenant_id,source_sn,action,change_type,change_amount,extra')->order('id')->select()->toArray():[];
    $diagnostics=[];if($consumptions)foreach(AiConsumptionEvent::whereIn('consumption_id',array_column($consumptions,'id'))->where('event_type','settlement_deferred')->field('consumption_id,payload_summary')->select()->toArray()as$event)$diagnostics[$event['consumption_id']][]=$event['payload_summary']['protocol_diagnostic']??[];
    foreach($scripts as&$script){$request=$decode($script['request_json']);$result=$decode($script['result_json']);$shotIds=array_column($result['storyboard']??[],'shot_id');
        $script['request']=['model_selections'=>$request['model_selections']??[],'episode_count'=>$request['episode_count']??1,'episode_number'=>$request['episode_number']??null];
        $script['output']=['title'=>$result['title']??'','scenes'=>count($result['scene_screenplays']??[]),'shots'=>count($shotIds),'unique_shots'=>count(array_unique($shotIds)),'subjects'=>count($result['subjects']??[]),'locations'=>count($result['locations']??[]),'screenplay_chars'=>mb_strlen(json_encode($result['scene_screenplays']??[],JSON_UNESCAPED_UNICODE)),
            'shot_seconds'=>array_sum(array_map(static fn($shot)=>(float)($shot['recommended_duration_seconds']??0),$result['storyboard']??[])),'duration_stats'=>$result['duration_stats']??[],'fixed_phrase_present'=>str_contains(json_encode($result,JSON_UNESCAPED_UNICODE),'球旧了，朋友不能丢')];
        unset($script['request_json'],$script['result_json']);}unset($script);
    foreach($units as&$unit){$r=$decode($unit['result_json']);$unit['receipt']=['consumption_id'=>$r['result']['consumption_id']??0,'model_code'=>$r['result']['model_code']??'','provider_request_id'=>$r['result']['provider_request_id']??'','billing'=>$r['result']['billing']??[]];unset($unit['result_json']);}unset($unit);
    $priceFields=array_flip(['sku_id','sku_key','usage_unit','usage_unit_size','upstream_price','platform_price','tenant_price','model_code','product_id','quantity']);
    foreach($consumptions as&$c){$snapshot=$c['price_snapshot'];$safe=array_intersect_key($snapshot,$priceFields);foreach(['input','output']as$key)if(isset($snapshot[$key]))$safe[$key]=array_intersect_key($snapshot[$key],$priceFields);$c['price_snapshot']=$safe;
        $c['diagnostics']=$diagnostics[$c['id']]??[];$matches=static function(array $logs)use($c,$decode):array{return array_values(array_filter($logs,static function($log)use($c,$decode){$extra=$decode($log['extra']);return (int)($extra['consumption_id']??0)===(int)$c['id']||$log['source_sn']===$c['consume_no']||str_starts_with($log['source_sn'],$c['consume_no'].'-');}));};
        $ul=$matches($userLogs);$tl=$matches($tenantLogs);$net=static fn($logs):float=>round(array_sum(array_map(static fn($l)=>((int)$l['action']===2?1:-1)*(float)$l['change_amount'],$logs)),2);
        $c['ledger']=['user_log_ids'=>array_column($ul,'id'),'tenant_log_ids'=>array_column($tl,'id'),'user_net'=>$net($ul),'tenant_net'=>$net($tl)];
        $terminal=in_array($c['billing_status'],['settled','deducted','refunded'],true);$c['ledger']['verified']=$terminal&&abs($net($ul)-(float)$c['actual_user_price'])<0.005&&abs($net($tl)-(float)$c['actual_tenant_cost'])<0.005;
    }unset($c);
    $result=['tenant_id'=>$tenant,'user_id'=>$user,'database'=>config('database.connections.mysql.database'),'exported_at'=>date(DATE_ATOM),'scripts'=>$scripts,'episodes'=>$episodes,'units'=>$units,'app_tasks'=>$tasks,'media'=>$media,'consumptions'=>$consumptions,
        'summary'=>['consumptions'=>count($consumptions),'ledger_verified'=>count(array_filter($consumptions,static fn($c)=>$c['ledger']['verified'])),'actual_user_points'=>round(array_sum(array_column($consumptions,'actual_user_price')),2),'actual_tenant_points'=>round(array_sum(array_column($consumptions,'actual_tenant_cost')),2)]];
}finally{Db::rollback();}
if(!is_dir(dirname($out)))mkdir(dirname($out),0700,true);file_put_contents($out,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);chmod($out,0600);echo json_encode($result['summary'])."\n";
