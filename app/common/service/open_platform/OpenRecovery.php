<?php
namespace app\common\service\open_platform;
use app\common\model\ai\AiConsumptionLog;
final class OpenRecovery
{
    public static function tick(int $limit=10): int
    {
        static $lastPrune=0;if($lastPrune<time()-60){OpenRateLimiter::prune();$lastPrune=time();}
        $count=0;foreach(['request','webhook'] as $table){$q=OpenStore::table($table)->where('next_run_time','>',0)->where('next_run_time','<=',time())->where('lease_expire','<=',time());if($table==='request')$q->where('active',1);else $q->where('status','pending');
            foreach($q->order('next_run_time','asc')->limit(min(100,$limit))->select()->toArray() as $row){$token=bin2hex(random_bytes(16));if(!OpenStore::table($table)->where('id',$row['id'])->where('lease_expire','<=',time())->update(['lease_token'=>$token,'lease_expire'=>time()+300]))continue;$row['lease_token']=$token;
                try{if($table==='webhook')OpenWebhooks::deliver($row);else self::refresh($row);$count++;}catch(\Throwable $e){if(self::connectionLost($e))throw $e;OpenStore::table($table)->where(['id'=>$row['id'],'lease_token'=>$token])->update(['next_run_time'=>time()+60,'lease_expire'=>0,'lease_token'=>'',...($table==='webhook'?['last_error'=>'通知恢复处理失败']:['error_code'=>$e instanceof OpenException?$e->errorType:'recovery_failed','error_message'=>$e instanceof OpenException?mb_substr($e->getMessage(),0,500):'恢复处理失败'])]);}
            }}return $count;
    }
    /** Never reconnect and replay SQL on an uncertain connection; let the supervisor restart. */
    public static function connectionLost(\Throwable $error): bool
    {
        do{
            $state='';$code=0;
            if($error instanceof \PDOException){$state=(string)($error->errorInfo[0]??$error->getCode());$code=(int)($error->errorInfo[1]??0);}
            elseif($error instanceof \think\db\exception\DbException){$data=$error->getData()['PDO Error Info']??[];$state=(string)($data['SQLSTATE']??'');$code=(int)($data['Driver Error Code']??0);}
            if(str_starts_with($state,'08')||in_array($code,[2006,2013,2055],true))return true;
            if(($error instanceof \PDOException||$error instanceof \think\db\exception\DbException)&&preg_match('/server has gone away|lost connection|connection (?:is )?closed|error (?:2006|2013|2055)/i',$error->getMessage()))return true;
        }while($error=$error->getPrevious());
        return false;
    }
    public static function refresh(array $row): void
    {
        $request=request();$request->source=\app\common\enum\AdminTerminalEnum::USER;$request->tenantId=(int)$row['tenant_id'];$request->userId=(int)$row['user_id'];$request->userInfo=['tenant_id'=>(int)$row['tenant_id'],'user_id'=>(int)$row['user_id'],'terminal'=>4];OpenCallContext::enter($row['request_id'],(string)($row['lease_token']??''));
        try{
            $current=OpenStore::table('request')->where('id',$row['id'])->find();if(!$current)throw new OpenException('not_found','恢复任务不存在',404);OpenCallContext::assertLease($current);$row=$current;
            $snapshot=OpenStore::json($row['snapshot']);
            OpenImageBatchDispatch::recover($row);
            if(OpenImageBatchDispatch::supports($row)&&$row['tool_task_id']===''&&!OpenStore::json($row['consumption_ids'])){
                $fitting=OpenStore::json(OpenStore::table('request')->where('id',$row['id'])->value('snapshot'))['fitting_dispatch']??[];
                if(($fitting['state']??'')==='interrupted'){OpenAdmission::finish($row['request_id'],['status'=>'failed','billing_status'=>'none','active'=>0,'error_code'=>'execution_not_started','error_message'=>'图片批次在创建可执行子任务前中断','finish_time'=>time(),'next_run_time'=>0,'lease_expire'=>0,'lease_token'=>'']);return;}
            }
            // A completed free read is a request receipt, not a second running task.
            if($row['method']==='GET'&&$row['billing_status']==='none'&&$row['response_blob']!==''&&(int)$row['response_code']===200&&(float)$row['actual_user']===0.&&(float)$row['actual_tenant']===0.&&(float)$row['budget_user']===0.&&(float)$row['budget_tenant']===0.){OpenAdmission::finish($row['request_id'],['status'=>'success','active'=>0,'finish_time'=>time(),'next_run_time'=>0,'lease_expire'=>0,'lease_token'=>'']);return;}
            if(($snapshot['_execution_version']??0)===2&&($snapshot['dispatch_started']??true)===false&&$row['status']==='accepted'){if($row['kind']==='market')OpenMarketBilling::settle($row,[],true);OpenAdmission::finish($row['request_id'],['status'=>'failed','billing_status'=>empty(OpenStore::json($row['consumption_ids']))?'none':'refunded','active'=>0,'budget_user'=>0,'budget_tenant'=>0,'error_code'=>'execution_not_started','error_message'=>'请求在业务执行前中断','next_run_time'=>0,'finish_time'=>time(),'lease_expire'=>0,'lease_token'=>'']);return;}
            if($row['kind']==='market'){
                $snapshot=OpenStore::json($row['snapshot']);$contract=$snapshot['contract']??[];
                // Unknown acceptance cannot be repaired by resubmitting the original payload.
                if($row['upstream_task_id']===''||empty($contract['query_path'])){OpenAdmission::finish($row['request_id'],['next_run_time'=>time()+300,'lease_expire'=>0,'lease_token'=>'']);return;}
                $source=OpenStore::json(OpenVault::open($snapshot['source_blob']));$path=str_replace('{task_id}',rawurlencode($row['upstream_task_id']),$contract['query_path']);$wire=(new OpenTransport())->send($source,'GET',$path,[]);if(OpenGateway::wireFailed($wire['status'],OpenStore::json($wire['body']),$contract))throw new OpenException('query_failed','任务查询暂不可用',502);$data=OpenStore::json($wire['body']);if(!OpenGateway::marketQueryAccepted($wire,$row['upstream_task_id'],$contract)){OpenAdmission::finish($row['request_id'],['error_code'=>'response_adapter_required','error_message'=>'查询响应协议尚未确认，原任务及预占保留','next_run_time'=>time()+60,'lease_expire'=>0,'lease_token'=>'']);return;}OpenGateway::observeTask($row,$data);$latest=OpenStore::table('request')->where('id',$row['id'])->find();if((int)$latest['active']===0)OpenWebhooks::enqueue($latest,$data);
            }elseif($row['app_code']==='aigc_llm'&&!OpenStore::json($row['consumption_ids'])&&in_array($row['path'],['/api/v1/tools/aigc_llm/tasks','/api/v1/tools/aigc_llm/stream'],true)){
                // Both fixed text services commit and associate consumption
                // before any transport. With no association, execution never
                // reached a provider even if entry into the service was marked.
                OpenAdmission::finish($row['request_id'],['status'=>'failed','billing_status'=>'none','active'=>0,'error_code'=>'execution_not_started','error_message'=>'文本服务在上游执行前中断','finish_time'=>time()]);
            }elseif($row['app_code']==='aigc_llm'&&OpenStore::json($row['consumption_ids'])){
                OpenTextLifecycle::refresh($row);
            }elseif($row['tool_task_id']!==''){
                $tool=OpenToolRegistry::get($row['app_code']);$op=$tool['operations']['GET tasks/{task_id}']??null;if(!$op)throw new OpenException('query_unavailable','任务查询尚未接入',503);
                $data=OpenToolAdapter::call($request,$op+['app_code'=>$row['app_code'],'route'=>'GET tasks/{task_id}','params'=>['task_id'=>$row['tool_task_id']],'history'=>true,'internal'=>true],[]);
                $status=(string)($data['status']??'running');if(in_array($status,['success','completed','failed','cancelled','canceled','partial_success','partial_failed'],true)){
                    $latest=OpenStore::table('request')->where('id',$row['id'])->find();$billing=OpenToolTasks::billing(array_replace($latest,['status'=>$status]));$unresolved=!in_array($billing,['settled','refunded','none'],true);
                    OpenAdmission::finish($row['request_id'],['status'=>$status,'billing_status'=>$billing,'active'=>$unresolved?1:0,'finish_time'=>time()]);$latest=OpenStore::table('request')->where('id',$row['id'])->find();OpenWebhooks::enqueue($latest,$data);
                }
            }
            $latest=OpenStore::table('request')->where('id',$row['id'])->find();OpenAdmission::finish($row['request_id'],['next_run_time'=>(int)$latest['active']===1?time()+60:0,'lease_expire'=>0,'lease_token'=>'']);
        }finally{OpenCallContext::leave();}
    }
}
