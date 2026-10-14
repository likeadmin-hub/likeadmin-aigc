<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\ai\{AiConsumptionLog,AiAppTask,AiConsumptionEvent};
use think\facade\{Db,Log};

/** Receipt recovery for synchronous STT. This service never calls the provider. */
final class AgentTranscriptionRecovery
{
    public static function prepareJournal(): string
    {
        $dir=runtime_path().'home-agent-stt-receipts';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('无法创建转写回执目录');
        if(!is_writable($dir))throw new \RuntimeException('转写回执目录不可写');
        return $dir;
    }
    private static function path(array $log): string
    {
        if(!preg_match('/^CSTT[a-f0-9]{24}$/',(string)$log['consume_no']))throw new \RuntimeException('转写记录标识无效');
        return self::prepareJournal().'/'.$log['consume_no'].'.json';
    }
    private static function owned(int $id,int $tenant=0): array
    {
        $q=AiConsumptionLog::where(['id'=>$id,'app_code'=>'aigc_llm','action_code'=>'agent_transcribe']);
        if($tenant>0)$q->where('tenant_id',$tenant);
        $log=$q->find();if(!$log)throw new \InvalidArgumentException('转写消费记录不存在或无权访问');
        return $log->toArray();
    }
    public static function record(int $id,array $wire,?array $context=null): void
    {
        $log=$context??self::owned($id);
        if((int)$log['id']!==$id||$log['app_code']!=='aigc_llm'||$log['action_code']!=='agent_transcribe')throw new \InvalidArgumentException('回执上下文不匹配');
        $data=json_decode($wire['body'],true);
        $receipt=['http_status'=>(int)$wire['http_status'],'provider_receipt'=>is_array($data)?$data:(mb_check_encoding($wire['body'],'UTF-8')?$wire['body']:null),
            'body_base64'=>mb_check_encoding($wire['body'],'UTF-8')?'':base64_encode($wire['body']),
            'complete'=>(bool)$wire['complete'],'transport_errno'=>(int)($wire['transport_errno']??0),
            'headers'=>$wire['headers']??[],'elapsed_ms'=>(int)($wire['elapsed_ms']??0),'received_at'=>time()];
        // Atomic, private journal survives a database outage between response and settlement.
        $path=self::path($log);$temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json=AgentStore::encode(['consumption_id'=>$id,'tenant_id'=>(int)$log['tenant_id'],'consume_no'=>$log['consume_no'],'receipt'=>$receipt]);
        $handle=fopen($temp,'x');if(!$handle)throw new \RuntimeException('无法保存转写回执');
        try{
            chmod($temp,0600);$offset=0;
            while($offset<strlen($json)){$written=fwrite($handle,substr($json,$offset));if(!$written)throw new \RuntimeException('转写回执写入失败');$offset+=$written;}
            fflush($handle);if(function_exists('fsync'))fsync($handle);
        }finally{fclose($handle);}
        if(!rename($temp,$path)){@unlink($temp);throw new \RuntimeException('转写回执保存失败');}
        self::persist($log,$receipt);
    }
    private static function persist(array $log,array $receipt): void
    {
        $data=is_array($receipt['provider_receipt']??null)?$receipt['provider_receipt']:[];
        $requestId=$receipt['headers']['x-request-id']??$receipt['headers']['request-id']??$data['request_id']??$data['data']['request_id']??'';
        $taskId=$receipt['headers']['x-task-id']??$data['task_id']??$data['data']['task_id']??'';
        AiConsumptionLog::where('id',$log['id'])->where('billing_status','reserved')->update([
            'response_summary'=>AgentStore::encode($receipt),'upstream_request_id'=>is_scalar($requestId)?substr((string)$requestId,0,100):'',
            'upstream_task_id'=>is_scalar($taskId)?substr((string)$taskId,0,100):'','update_time'=>time()]);
    }
    public static function classify(array $receipt,array $request): ?array
    {
        if(($receipt['complete']??true)!==true)return null;
        $status=(int)($receipt['http_status']??0);$data=$receipt['provider_receipt']??null;
        if(!is_array($data)||$status<200||$status>=500)return null;
        if((isset($data['code'])&&(string)$data['code']==='0')||isset($data['error']))return ['success'=>false,'result'=>['error'=>'语音识别服务拒绝了请求，请检查素材后重试']];
        $text=$data['data']['text']??$data['data']['result']['text']??null;
        if($status!==200||(string)($data['code']??'')!=='1'||!is_string($text)||mb_strlen($text)>100000)return null;
        return ['success'=>true,'result'=>['text'=>$text,'file_id'=>$request['file_id']??'',
            'language'=>(string)($data['data']['language']??$data['data']['result']['language']??''),
            'duration'=>(float)($data['data']['duration']??$data['data']['result']['duration']??0)]];
    }
    private static function timestamp($value): int
    {
        return is_numeric($value)?(int)$value:(int)strtotime((string)$value);
    }
    public static function reconcile(int $id,int $tenant=0): array
    {
        $log=self::owned($id,$tenant);
        // Do not interfere with an in-flight 180 second synchronous submission.
        if($log['billing_status']==='reserved'&&$log['run_status']==='running'&&self::timestamp($log['create_time'])>time()-240)return ['resolved'=>false,'reason'=>'转写请求仍在处理中'];
        if($log['billing_status']==='reserved'){
            $path=self::path($log);
            if(is_file($path)){
                $journal=AgentStore::json(file_get_contents($path));
                if(($journal['consumption_id']??0)!==$id||($journal['tenant_id']??0)!==(int)$log['tenant_id']||($journal['consume_no']??'')!==$log['consume_no'])throw new \RuntimeException('转写回执归属不匹配');
                self::persist($log,$journal['receipt']);
            }
            AgentTranscriptionTool::reconcileReceipt($id);
        }
        $log=self::owned($id,$tenant);$resolved=in_array($log['billing_status'],['settled','refunded'],true);
        if($resolved){
            self::wake($log);
            $path=self::path($log);if(is_file($path))unlink($path);
        }else self::alert($log);
        AiConsumptionLog::where('id',$id)->update(['refresh_requested_at'=>time()]);
        return ['resolved'=>$resolved,'task_id'=>(int)$log['app_task_id'],'billing_status'=>$log['billing_status'],
            'reserved_points'=>(float)$log['reserved_user_price'],'reason'=>$resolved?'已依据回执恢复结算及任务状态':'回执不足，保留预占；需核对上游原始记录，不会重新提交'];
    }
    private static function wake(array $log): void
    {
        Db::transaction(function()use($log){
            $task=AiAppTask::where(['id'=>$log['app_task_id'],'tenant_id'=>$log['tenant_id'],'user_id'=>$log['user_id'],'business_table'=>'aigc_llm_agent_step'])->find();
            if(!$task)return;
            $step=AgentStore::owner('step',(int)$log['tenant_id'],(int)$log['user_id'])->where(['id'=>$task['business_id'],'task_id'=>$task['id'],'tool_code'=>AgentTranscriptionTool::CODE])->find();
            if(!$step)return;
            $run=AgentStore::owner('run',(int)$log['tenant_id'],(int)$log['user_id'])->where('id',$step['run_id'])->lock(true)->find();
            if(!$run||(int)$run['cancel_requested']||!in_array($run['status'],['needs_review','waiting_tasks','running'],true)||($run['lease_token']!==''&&(int)$run['lease_until']>time()))return;
            if(in_array($step['status'],['success','failed','canceled'],true))return;
            // Existing executor consumes the recovered task and resumes only its original run.
            AgentStore::table('step')->where('id',$step['id'])->update(['status'=>'running','error'=>'','update_time'=>time()]);
            AgentStore::table('run')->where('id',$run['id'])->update(['status'=>'waiting_tasks','phase'=>'tools','error'=>'','next_run_time'=>0,'lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
            AgentStore::event($run,'task.reconciled',['step_id'=>$step['id'],'status'=>$task['status']]);
        });
    }
    private static function alert(array $log): void
    {
        Db::transaction(function()use($log){
            AiConsumptionLog::where('id',$log['id'])->lock(true)->find();
            if(AiConsumptionEvent::where(['consumption_id'=>$log['id'],'event_type'=>'stt.review_required'])->count())return;
            self::event((int)$log['id'],'stt.review_required',['task_id'=>(int)$log['app_task_id'],'reserved_points'=>(float)$log['reserved_user_price'],'reason'=>'缺少可结算回执，请核对上游原始调用记录']);
            Log::warning('Home Agent STT review required: task '.$log['app_task_id'].', consumption '.$log['id']);
        });
    }
    private static function event(int $id,string $type,array $payload): void
    {
        AiConsumptionEvent::create(['consumption_id'=>$id,'event_type'=>$type,'event_status'=>'recorded','attempt_no'=>1,'payload_summary'=>$payload,'payload_ciphertext'=>'','http_status'=>0,'elapsed_ms'=>0,'create_time'=>time()]);
    }
    public static function scan(int $limit=25): array
    {
        // Include terminal bills whose Agent step was interrupted before seeing settlement.
        $ids=AiConsumptionLog::alias('c')->join('ai_app_task t','t.id=c.app_task_id')
            ->join('aigc_llm_agent_step s','s.id=t.business_id AND s.task_id=t.id AND s.tenant_id=c.tenant_id AND s.user_id=c.user_id')
            ->where(['c.app_code'=>'aigc_llm','c.action_code'=>'agent_transcribe','t.business_table'=>'aigc_llm_agent_step'])
            ->whereIn('s.status',['submitting','running','needs_review'])->where('c.create_time','<',time()-240)
            ->where('c.refresh_requested_at','<',time()-60)->order('c.refresh_requested_at')->limit(max(1,min(100,$limit)))->column('c.id');
        $result=['checked'=>0,'resolved'=>0,'pending'=>0];
        foreach($ids as $id){try{$r=self::reconcile((int)$id);$result[$r['resolved']?'resolved':'pending']++;}catch(\Throwable $e){Log::error('STT reconciliation failed: consumption '.$id.' '.get_class($e));} $result['checked']++;}
        return $result;
    }
    /** Read-only operator audit; never replays a paid request or mutates a wallet. */
    public static function audit(int $tenant,int $task): array
    {
        $q=AiConsumptionLog::where(['app_code'=>'aigc_llm','action_code'=>'agent_transcribe']);
        if($tenant>0)$q->where('tenant_id',$tenant);
        if($task>0)$q->where('app_task_id',$task);else $q->where('billing_status','reserved');
        $rows=$q->order('id')->limit(100)->select()->toArray();
        return array_map(static fn($r)=>array_intersect_key($r,array_flip(['id','app_task_id','tenant_id','user_id','consume_no','run_status','billing_status','reserved_user_price','actual_user_price','upstream_request_id','upstream_task_id','error_code','create_time'])),$rows);
    }
    /** Explicit operator evidence import. Both tenant and original task number are required. */
    public static function importEvidence(int $id,int $tenant,array $evidence,string $operator,string $reason,bool $apply): array
    {
        if($tenant<1||trim($operator)===''||trim($reason)==='')throw new \InvalidArgumentException('必须提供租户、操作人和核对依据说明');
        $log=self::owned($id,$tenant);$task=AiAppTask::find($log['app_task_id']);
        if(($evidence['task_no']??'')!==$task['task_no']||(int)($evidence['tenant_id']??0)!==$tenant)throw new \InvalidArgumentException('核对凭据与原任务不匹配');
        $receipt=$evidence['receipt']??[];
        if(!is_array($receipt)||($receipt['complete']??null)!==true||self::classify($receipt,AgentStore::json($log['request_summary']))===null)throw new \InvalidArgumentException('凭据缺少完整明确的成功或拒绝回执');
        if($log['billing_status']!=='reserved')throw new \InvalidArgumentException('任务已经结算或退款，不允许覆盖');
        if($log['run_status']==='running'&&self::timestamp($log['create_time'])>time()-240)throw new \InvalidArgumentException('任务仍在处理中');
        $preview=['task_id'=>(int)$task['id'],'decision'=>self::classify($receipt,AgentStore::json($log['request_summary']))['success']?'settle':'refund','points'=>(float)$log['reserved_user_price'],'apply'=>$apply];
        if(!$apply)return $preview;
        Db::transaction(function()use($id,$log,$receipt,$operator,$reason){
            $locked=AiConsumptionLog::where('id',$id)->lock(true)->find();
            if($locked['billing_status']!=='reserved')throw new \RuntimeException('账务状态已变化，请重新核对');
            // Never override already captured complete evidence with a manual decision.
            $existing=AgentStore::json($locked['response_summary']);
            if(self::classify($existing,AgentStore::json($log['request_summary']))!==null)throw new \RuntimeException('已有明确回执，请直接恢复现有回执');
            self::persist($log,$receipt);
            self::event($id,'stt.operator_evidence',['operator'=>mb_substr($operator,0,120),'reason'=>mb_substr($reason,0,1000),'sha256'=>hash('sha256',AgentStore::encode($receipt))]);
            AgentTranscriptionTool::reconcileReceipt($id);
        });
        return $preview+self::reconcile($id,$tenant);
    }
}
