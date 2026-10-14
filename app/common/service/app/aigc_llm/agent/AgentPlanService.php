<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use think\facade\Db;
use app\common\model\app\aigc_llm\AigcLlmSession;
use app\common\service\open_platform\OpenSchema;

/** User changes only prepare plans. All paid submissions remain in the existing worker. */
final class AgentPlanService
{
    private const FIELDS=['prompt','negative_prompt','text','lyrics','style','duration','quantity','ratio','quality','resolution','instrumental'];

    public static function version(array $step): string
    {
        return hash('sha256',AgentStore::encode(array_intersect_key($step,array_flip(['id','status','task_id','prepared','result','error']))));
    }
    public static function actions(array $run,array $step): array
    {
        $idle=in_array($run['status'],AgentStore::TERMINAL,true);
        $attempts=AgentStore::json($step['prepared'])['_attempts']??[];
        $retired=!empty(AgentStore::json($step['prepared'])['_superseded_by'])||!empty(AgentStore::json($step['prepared'])['_removed']);
        return ['version'=>self::version($step),'editable'=>$run['status']==='awaiting_confirmation'&&$step['status']==='proposed'&&!(int)$step['task_id'],
            'retryable'=>$idle&&!$retired&&in_array($step['status'],['failed','canceled'],true)&&count($attempts)<3,
            'checkable'=>$idle&&(int)$step['task_id']>0&&in_array($step['status'],['needs_review','running','submitting'],true),
            'attempts'=>count($attempts)];
    }
    private static function step(array $run,int $id): array
    {
        $row=AgentStore::owner('step',(int)$run['tenant_id'],(int)$run['user_id'])->where(['id'=>$id,'run_id'=>$run['id']])->find();
        if(!$row)throw new \InvalidArgumentException('步骤不存在');
        return $row;
    }
    private static function lock(int $tenant,int $user,int $id): array
    {
        $run=AgentStore::run($tenant,$user,$id);
        $session=AigcLlmSession::where(['id'=>$run['session_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
        if(!$session)throw new \InvalidArgumentException('会话不存在');
        $run=AgentStore::owner('run',$tenant,$user)->where('id',$id)->lock(true)->find();
        if((int)AgentStore::owner('run',$tenant,$user)->where('session_id',$run['session_id'])->max('id')!==$id)throw new \InvalidArgumentException('请在最新执行记录中继续，历史结果已保留');
        if(in_array($run['status'],['queued','running','waiting_tasks'],true))throw new \InvalidArgumentException('任务正在执行，请等待完成');
        return $run;
    }
    private static function fields(array $run,string $code): array
    {
        $schema=AgentCapabilityCatalog::schema($code);
        $fixed=AgentComposerSettings::parameters(AgentStore::json($run['preferences']),$code);
        return array_diff_key(array_intersect_key($schema['properties']??[],array_flip(self::FIELDS)),$fixed);
    }
    public static function editor(int $tenant,int $user,int $id,int $stepId): array
    {
        AgentStore::access($tenant,$user);$run=AgentStore::run($tenant,$user,$id);$step=self::step($run,$stepId);
        if(!self::actions($run,$step)['editable'])throw new \InvalidArgumentException('只能编辑尚未提交的待确认步骤');
        AgentCapabilityCatalog::describe($run,$step['tool_code']);
        $prepared=AgentStore::json($step['prepared']);$fields=self::fields($run,$step['tool_code']);
        return ['approval_hash'=>$run['approval_hash'],'title'=>$step['title'],'fields'=>$fields,'values'=>array_intersect_key($prepared['inputs']??[],$fields)];
    }
    private static function publish(array $run,string $event): void
    {
        $steps=AgentStore::owner('step',(int)$run['tenant_id'],(int)$run['user_id'])->where('run_id',$run['id'])->order('id')->select()->toArray();
        $p=AgentStore::json($run['preferences']);$p['_plan_revision']=(int)($p['_plan_revision']??0)+1;
        $hash=hash('sha256',AgentStore::encode([$p['_plan_revision'],array_map(static fn($s)=>[$s['id'],$s['status'],$s['prepared']],$steps)]));
        AgentStore::update($run,['preferences'=>AgentStore::encode($p),'status'=>'awaiting_confirmation','phase'=>'chat','approval_hash'=>$hash,'cancel_requested'=>0,'error'=>'','lease_token'=>'','lease_until'=>0,'next_run_time'=>0]);
        AigcLlmSession::where(['id'=>$run['session_id'],'tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id']])->update(['status'=>'idle']);
        AgentStore::event($run,$event,['revision'=>$p['_plan_revision']]);
    }
    public static function edit(int $tenant,int $user,int $id,int $stepId,string $hash,array $changes,bool $remove=false): array
    {
        AgentStore::access($tenant,$user);$snapshot=AgentStore::run($tenant,$user,$id);$step=self::step($snapshot,$stepId);
        if($hash===''||!hash_equals((string)$snapshot['approval_hash'],$hash)||!self::actions($snapshot,$step)['editable'])throw new \InvalidArgumentException('方案已变更，请刷新后编辑');
        $old=AgentStore::json($step['prepared']);$fields=self::fields($snapshot,$step['tool_code']);
        if(array_diff_key($changes,$fields))throw new \InvalidArgumentException('包含不可编辑的参数');
        OpenSchema::validateRequest($changes,['type'=>'object','properties'=>$fields,'additionalProperties'=>false],true);
        // Pricing/admission may use external services. Never hold session locks while preparing.
        $fresh=$remove?$old:AgentCapabilityCatalog::prepare($snapshot,$step['tool_code'],array_replace($old['inputs']??[],$changes));
        if(isset($old['_attempts']))$fresh['_attempts']=$old['_attempts'];
        Db::transaction(static function()use($tenant,$user,$id,$stepId,$hash,$fresh,$remove){
            $run=self::lock($tenant,$user,$id);$step=self::step($run,$stepId);
            if(!hash_equals((string)$run['approval_hash'],$hash)||!self::actions($run,$step)['editable'])throw new \InvalidArgumentException('方案已变更，请刷新后编辑');
            if($remove&&AgentStore::owner('step',$tenant,$user)->where(['run_id'=>$id,'status'=>'proposed'])->count()<=1)throw new \InvalidArgumentException('最后一个步骤请使用“取消方案”');
            if($remove){$fresh['_removed']=true;}
            AgentStore::owner('step',$tenant,$user)->where('id',$stepId)->update(['prepared'=>AgentStore::encode($fresh),'status'=>$remove?'canceled':'proposed','step_key'=>hash('sha256',AgentStore::encode([$stepId,$fresh])),'update_time'=>time()]);
            self::publish($run,$remove?'plan.step_removed':'plan.edited');
        });
        return AgentExecutionService::detail($tenant,$user,$id);
    }
    public static function retry(int $tenant,int $user,int $id,int $stepId,string $version): array
    {
        AgentStore::access($tenant,$user);$snapshot=AgentStore::run($tenant,$user,$id);$step=self::step($snapshot,$stepId);$old=AgentStore::json($step['prepared']);
        if(($old['_retry_source_version']??'')===$version&&$version!=='')return AgentExecutionService::detail($tenant,$user,$id);
        if(!hash_equals(self::version($step),$version)||!self::actions($snapshot,$step)['retryable'])throw new \InvalidArgumentException('该步骤不能重试，请刷新后核对');
        if((int)$step['task_id']>0){
            $task=AgentCapabilityCatalog::query($snapshot,$step['tool_code'],(int)$step['task_id']);
            if(!in_array($task['status']??'',['failed','error','canceled','cancelled'],true)||!empty($task['results'])||!in_array($task['billing_status']??'',['refunded','none','prechecked'],true))throw new \InvalidArgumentException('原任务结果或费用尚未核对完成，暂不能重试');
        }else{
            foreach(AgentStore::owner('event',$tenant,$user)->where(['run_id'=>$id,'event_type'=>'tool.started'])->column('payload') as $payload)if((int)(AgentStore::json($payload)['step_id']??0)===$stepId)throw new \InvalidArgumentException('此步骤已有提交记录，请先核对，不能重复提交');
        }
        $fresh=AgentCapabilityCatalog::prepare($snapshot,$step['tool_code'],$old['inputs']??[]);
        $fresh['_attempts']=array_merge($old['_attempts']??[],[['task_id'=>(int)$step['task_id'],'status'=>$step['status'],'result'=>AgentStore::json($step['result']),'prepared'=>array_diff_key($old,['_attempts'=>true]),'time'=>time()]]);
        $fresh['_retry_source_version']=$version;
        Db::transaction(static function()use($tenant,$user,$id,$stepId,$version,$fresh){
            $run=self::lock($tenant,$user,$id);$step=self::step($run,$stepId);$prepared=AgentStore::json($step['prepared']);
            if(($prepared['_retry_source_version']??'')===$version)return;
            if(!hash_equals(self::version($step),$version)||!self::actions($run,$step)['retryable'])throw new \InvalidArgumentException('步骤状态已变更，请刷新');
            $fresh['_retry_of']=$stepId;
            $newId=AgentStore::table('step')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'run_id'=>$id,'title'=>$step['title'],'tool_code'=>$step['tool_code'],'prepared'=>AgentStore::encode($fresh),'status'=>'proposed','task_id'=>0,'result'=>'','error'=>'','step_key'=>hash('sha256',AgentStore::encode([$stepId,$fresh])),'create_time'=>time(),'update_time'=>time()]);
            $prepared['_superseded_by']=$newId;$prepared['_retry_source_version']=$version;
            AgentStore::owner('step',$tenant,$user)->where('id',$stepId)->update(['prepared'=>AgentStore::encode($prepared),'update_time'=>time()]);
            self::publish($run,'plan.retry_prepared');
        });
        return AgentExecutionService::detail($tenant,$user,$id);
    }
    public static function check(int $tenant,int $user,int $id,int $stepId,string $version): array
    {
        AgentStore::access($tenant,$user);$snapshot=AgentStore::run($tenant,$user,$id);$step=self::step($snapshot,$stepId);
        if(!hash_equals(self::version($step),$version)||!self::actions($snapshot,$step)['checkable'])throw new \InvalidArgumentException('步骤状态已变更，请刷新');
        // Query an existing task only. Never recreate a lost supplier submission.
        $result=AgentCapabilityCatalog::query($snapshot,$step['tool_code'],(int)$step['task_id']);
        $status=(string)($result['status']??'');$done=in_array($status,['success','completed','succeeded','partial_success'],true);$failed=in_array($status,['failed','error','canceled','cancelled'],true);
        if($done)AgentArtifactService::collect($snapshot,$step,$result);
        Db::transaction(static function()use($tenant,$user,$id,$stepId,$version,$result,$done,$failed){
            $run=self::lock($tenant,$user,$id);$step=self::step($run,$stepId);
            if(!hash_equals(self::version($step),$version))return;
            AgentStore::owner('step',$tenant,$user)->where('id',$stepId)->update(['status'=>$done?'success':($failed?'failed':'needs_review'),'result'=>AgentStore::encode($result),'error'=>$done?'':($failed?'原任务已确认失败':'原任务尚未确认完成，请稍后再核对'),'update_time'=>time()]);
            // Resume only polling/continuation through the original worker; no old approval authorizes a new submission.
            $pending=AgentStore::owner('step',$tenant,$user)->where(['run_id'=>$id,'status'=>'proposed'])->count();
            if($pending)self::publish($run,'plan.recovery_checked');
            elseif($done||$failed){
                AgentStore::update($run,['phase'=>'tools','status'=>'waiting_tasks','lease_token'=>'','lease_until'=>0,'next_run_time'=>0]);
            }
            AgentStore::event($run,'task.checked',['step_id'=>$stepId]);
        });
        return AgentExecutionService::detail($tenant,$user,$id);
    }
}
