<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaStoryDraft;
use app\common\service\app\aigc_short_drama\ShortDramaRevisionScope;
use RuntimeException;
use think\facade\Db;

/** One revision uses the existing production/billing path, never a text-only writeback. */
final class ConversationProductionRevision
{
    public static function task(int $tenant,int $user,string $id,bool $lock=false): array
    {
        $task=Db::name('aigc_short_drama_script_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$id,'delete_time'=>0])->lock($lock)->find();
        if (!$task) throw new RuntimeException('WORKFLOW_PRODUCTION_NOT_FOUND');
        return $task;
    }
    public static function stamp(array $task): string
    {
        return hash('sha256',(string)$task['request_json'].'|'.(string)$task['result_json']);
    }
    public static function snapshot(int $tenant,int $user,array $state): array
    {
        $result=[];
        foreach (ConversationWorkflow::revisableStages($state) as $stage) {
            $key=$stage==='episode'?'episode_'.ConversationSeries::episode($state):$stage;
            $binding=$state['series']['tasks'][$key]??[];
            if (empty($binding['task_id'])) continue;
            $task=self::task($tenant,$user,$binding['task_id']);
            if ($task['status']!=='success') continue;
            $result[$stage]=['task_id'=>$binding['task_id'],'stamp'=>self::stamp($task),'project_id'=>(int)$task['project_id']];
        }
        return $result;
    }
    public static function reopen(array $state,string $stage,string $message,array $base): array
    {
        if (empty($base['task_id']) || empty($base['stamp']) || mb_strlen($message)>2000) throw new RuntimeException('WORKFLOW_REVISION_UNAVAILABLE');
        $state['revision_request']=['stage'=>$stage,'content'=>$message,'base'=>$base,'return_stage'=>$state['stage_state'],
            'return_plan'=>$state['stage_plan'] ?? [],'return_image_plan'=>$state['image_plan'] ?? [],'return_plan_hash'=>$state['plan_hash'] ?? '',
            'mode'=>'replace','artifact_memory'=>$state['artifact_memory']];
        $state['stage_state']=['key'=>$stage,'status'=>'ready','completed'=>$state['stage_state']['completed']??[]];
        $state['stage_plan']=[];$state['image_plan']=[];$state['plan_hash']='';
        $state['state_revision']++;
        return $state;
    }
    public static function bind(int $tenant,int $user,int $run,array &$state): array
    {
        $revision=&$state['revision_request'];
        $project=Db::name('aigc_short_drama_project')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>(int)$revision['base']['project_id'],'delete_time'=>0])->lock(true)->find();
        if (!$project) throw new RuntimeException('WORKFLOW_PRODUCTION_NOT_FOUND');
        $source=self::task($tenant,$user,(string)$revision['base']['task_id'],true);
        if (!hash_equals($revision['base']['stamp'],self::stamp($source))) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
        if (!empty($revision['binding'])) {
            $binding=$revision['binding'];
            $task=self::task($tenant,$user,$binding['task_id']);
            if (in_array($task['status'],['failed','canceled'],true) && (int)$binding['run_id']!==$run) {
                AigcShortDramaService::retry($tenant,$user,$binding['task_id']);
                $binding['run_id']=$run;$revision['binding']=$binding;
            }
            return $binding;
        }
        $latest=$project['last_task_id'];
        if ($latest!==$source['task_id']) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
        $request=json_decode($source['request_json'],true)?:[];
        $plan=ShortDramaStoryDraft::effective($request,json_decode($source['result_json'],true)?:[]);
        // A local edit must resolve to stable IDs. Never turn an ambiguous edit into a whole rewrite.
        if ($revision['stage']==='episode') {
            $target=ShortDramaRevisionScope::shotTarget($revision['content'],$plan);
            // Natural multi-object edits are planned and accepted by the shared revision engine.
            $revision['target']=$target;
        }
        $binding=AigcShortDramaService::message($tenant,$user,['project_id'=>(int)$source['project_id'],'task_id'=>$source['task_id'],
            'message'=>$revision['content'],'draft_version'=>ShortDramaStoryDraft::version($request),
            'submission_key'=>'canvas-revision:'.$run.':'.$source['task_id']]);
        $revision['binding']=['task_id'=>$binding['task_id'],'project_id'=>(int)$source['project_id'],'run_id'=>$run];
        return $revision['binding'];
    }
    public static function adopt(array &$state,int $tenant,int $user): void
    {
        $revision=$state['revision_request'];
        $project=Db::name('aigc_short_drama_project')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>(int)$revision['base']['project_id'],'delete_time'=>0])->lock(true)->find();
        if (!$project) throw new RuntimeException('WORKFLOW_PRODUCTION_NOT_FOUND');
        $source=self::task($tenant,$user,$revision['base']['task_id'],true);
        if (!hash_equals($revision['base']['stamp'],self::stamp($source))) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
        $binding=$revision['binding'];$task=self::task($tenant,$user,$binding['task_id'],true);
        if ($task['status']!=='success') throw new RuntimeException('WORKFLOW_PRODUCTION_NOT_FOUND');
        $latest=$project['last_task_id'];
        if ($latest!==$task['task_id'] || !hash_equals((string)($revision['result_stamp']??''),self::stamp($task))) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
        $key=$revision['stage']==='episode'?'episode_'.ConversationSeries::episode($state):$revision['stage'];
        $state['series']['task_history'][$key][]=$state['series']['tasks'][$key];
        $state['series']['tasks'][$key]=$binding;
    }

    /** Preserve the old graph/confirmation card when the editor only answers or asks a question. */
    public static function dialogueState(array $state,array $task,string $text,int $run): ?array
    {
        $revision=$state['revision_request'] ?? [];
        if (($revision['binding']['task_id'] ?? '')!==($task['task_id'] ?? '') || (int)($revision['binding']['run_id'] ?? 0)!==$run || ($task['status'] ?? '')!=='success') return null;
        $result=json_decode((string)$task['result_json'],true) ?: [];
        $reply=$result['revision_response'] ?? [];
        if (!in_array($reply['action'] ?? '',['answer','clarify'],true) || ($reply['edited'] ?? true)!==false || ($reply['message'] ?? '')!==$text) return null;
        $key=$revision['stage']==='episode'?'episode_'.ConversationSeries::episode($state):$revision['stage'];
        $state['series']['tasks'][$key]=$revision['binding'];
        $state['stage_state']=$revision['return_stage'];
        $state['stage_plan']=$revision['return_plan'] ?? [];
        $state['image_plan']=$revision['return_image_plan'] ?? [];
        $state['plan_hash']=$revision['return_plan_hash'] ?? '';
        $state['artifact_memory']=$revision['artifact_memory'];
        unset($state['revision_request']);
        $state['state_revision']++;
        return $state;
    }
}
