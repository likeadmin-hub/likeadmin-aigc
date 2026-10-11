<?php
namespace app\common\service\open_platform;
use app\common\model\ai\AiConsumptionLog;
final class OpenRecovery
{
    public static function tick(int $limit=10): int
    {
        $count=0;foreach(['request','webhook'] as $table){$q=OpenStore::table($table)->where('next_run_time','>',0)->where('next_run_time','<=',time())->where('lease_expire','<=',time());if($table==='request')$q->where('active',1);else $q->where('status','pending');
            foreach($q->order('next_run_time','asc')->limit(min(100,$limit))->select()->toArray() as $row){$token=bin2hex(random_bytes(16));if(!OpenStore::table($table)->where('id',$row['id'])->where('lease_expire','<=',time())->update(['lease_token'=>$token,'lease_expire'=>time()+300]))continue;$row['lease_token']=$token;
                try{if($table==='webhook')OpenWebhooks::deliver($row);else self::refresh($row);$count++;}catch(\Throwable $e){OpenStore::table($table)->where(['id'=>$row['id'],'lease_token'=>$token])->update(['next_run_time'=>time()+60,'lease_expire'=>0,'lease_token'=>'','error_code'=>$e instanceof OpenException?$e->errorType:'recovery_failed','error_message'=>$e instanceof OpenException?mb_substr($e->getMessage(),0,500):'恢复处理失败']);}
            }}return $count;
    }
    public static function refresh(array $row): void
    {
        $request=request();$request->source=\app\common\enum\AdminTerminalEnum::USER;$request->tenantId=(int)$row['tenant_id'];$request->userId=(int)$row['user_id'];$request->userInfo=['tenant_id'=>(int)$row['tenant_id'],'user_id'=>(int)$row['user_id'],'terminal'=>4];OpenCallContext::enter($row['request_id']);
        try{
            $snapshot=OpenStore::json($row['snapshot']);
            if(($snapshot['_execution_version']??0)===2&&($snapshot['dispatch_started']??true)===false&&$row['status']==='accepted'){if($row['kind']==='market')OpenMarketBilling::settle($row,[],true);OpenAdmission::finish($row['request_id'],['status'=>'failed','billing_status'=>empty(OpenStore::json($row['consumption_ids']))?'none':'refunded','active'=>0,'budget_user'=>0,'budget_tenant'=>0,'error_code'=>'execution_not_started','error_message'=>'请求在业务执行前中断','next_run_time'=>0,'finish_time'=>time(),'lease_expire'=>0,'lease_token'=>'']);return;}
            if($row['kind']==='market'){
                $snapshot=OpenStore::json($row['snapshot']);$contract=$snapshot['contract']??[];
                // Unknown acceptance cannot be repaired by resubmitting the original payload.
                if($row['upstream_task_id']===''||empty($contract['query_path'])){OpenAdmission::finish($row['request_id'],['next_run_time'=>time()+300,'lease_expire'=>0,'lease_token'=>'']);return;}
                $source=OpenStore::json(OpenVault::open($snapshot['source_blob']));$path=str_replace('{task_id}',rawurlencode($row['upstream_task_id']),$contract['query_path']);$wire=(new OpenTransport())->send($source,'GET',$path,[]);if($wire['status']>=400)throw new OpenException('query_failed','任务查询暂不可用',502);$data=OpenStore::json($wire['body']);OpenGateway::observeTask($row,$data);$latest=OpenStore::table('request')->where('id',$row['id'])->find();if((int)$latest['active']===0)OpenWebhooks::enqueue($latest,$data);
            }elseif($row['tool_task_id']!==''){
                $tool=OpenToolRegistry::get($row['app_code']);$op=$tool['operations']['GET tasks/{task_id}']??null;if(!$op)throw new OpenException('query_unavailable','任务查询尚未接入',503);
                $data=OpenToolAdapter::call($request,$op+['app_code'=>$row['app_code'],'route'=>'GET tasks/{task_id}','params'=>['task_id'=>$row['tool_task_id']],'history'=>true,'internal'=>true],[]);
                $status=(string)($data['status']??'running');if(in_array($status,['success','completed','failed','cancelled','canceled','partial_success'],true)){
                    $ids=OpenStore::json(OpenStore::table('request')->where('id',$row['id'])->value('consumption_ids'));$billing='settled';if($ids){$states=AiConsumptionLog::where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']])->whereIn('id',$ids)->column('billing_status');foreach($states as $state)if(!in_array($state,['settled','refunded','none'],true)){$billing=$state==='pending_usage'?'pending_usage':'pending';break;}}
                    OpenAdmission::finish($row['request_id'],['status'=>$status,'billing_status'=>$billing,'active'=>$billing==='pending'||$billing==='pending_usage'?1:0,'budget_user'=>in_array($billing,['pending','pending_usage'],true)?$row['budget_user']:0,'budget_tenant'=>in_array($billing,['pending','pending_usage'],true)?$row['budget_tenant']:0,'finish_time'=>time()]);$latest=OpenStore::table('request')->where('id',$row['id'])->find();OpenWebhooks::enqueue($latest,$data);
                }
            }
            $latest=OpenStore::table('request')->where('id',$row['id'])->find();OpenAdmission::finish($row['request_id'],['next_run_time'=>(int)$latest['active']===1?time()+60:0,'lease_expire'=>0,'lease_token'=>'']);
        }finally{OpenCallContext::leave();}
    }
}
