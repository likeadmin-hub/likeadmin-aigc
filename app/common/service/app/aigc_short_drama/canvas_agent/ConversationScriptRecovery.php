<?php
namespace app\common\service\app\aigc_short_drama\canvas_agent;
use think\facade\Db;
use RuntimeException;
use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;

/** Explicit, scoped repair from a saved receipt. No provider, task retry or billing. */
final class ConversationScriptRecovery
{
    public static function recover(int $tenant,int $user,int $canvas,int $thread): array
    {
        return Db::transaction(static function()use($tenant,$user,$canvas,$thread):array {
            $scope=['tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0];
            $graph=Db::name(GraphService::TABLE)->where($scope+['id'=>$canvas])->lock(true)->find();
            $row=Db::name(ConversationStore::PREFIX.'thread')->where($scope+['id'=>$thread,'canvas_id'=>$canvas])->lock(true)->find();
            if(!$graph || !$row) throw new RuntimeException('CONVERSATION_NOT_FOUND');
            if((int)$row['active_run_id']!==0) throw new RuntimeException('RUN_IN_PROGRESS');
            $settings=json_decode($row['settings_json'],true);$state=$settings['workflow_state']??[];
            $episode=ConversationSeries::episode($state);$binding=$state['series']['tasks']['episode_'.$episode]??[];
            if(!$binding) throw new RuntimeException('WORKFLOW_PRODUCTION_NOT_FOUND');
            $task=AigcShortDramaScriptTask::where($scope+['task_id'=>$binding['task_id']])->findOrEmpty();
            if($task->isEmpty() || $task['status']!=='success') throw new RuntimeException('WORKFLOW_EPISODE_SCRIPT_MISSING');
            $plan=json_decode($task['result_json'],true)?:[];
            $old=ConversationSeries::text($plan,'episode',$episode,false);
            $new=ConversationSeries::text($plan,'episode',$episode);
            if($old===$new) return ['changed'=>false];
            $message=Db::name(ConversationStore::PREFIX.'message')->where($scope+['thread_id'=>$thread,'canvas_id'=>$canvas,'run_id'=>$binding['run_id'],'role'=>'assistant'])->lock(true)->find();
            if(!$message) throw new RuntimeException('CONVERSATION_NOT_FOUND');
            $content=json_decode($message['content_json'],true);$artifacts=$content['stage_artifacts']??[];
            $saved=implode('',array_column($artifacts,'content'));
            if($saved===$new) return ['changed'=>false];
            if($saved!==$old) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            $parts=mb_str_split($new,18000,'UTF-8');
            if(count($parts)!==count($artifacts)) throw new RuntimeException('WORKFLOW_RECOVERY_LAYOUT_CHANGED');
            $nodes=json_decode($graph['nodes_json'],true);$restored=0;
            foreach($parts as $i=>$text) {
                $key='episode_'.$episode.'_'.($i+1);$memoryIndex=null;
                foreach($state['artifact_memory'] as $j=>$item) if(($item['key']??'')===$key && ($item['stage']??'')==='episode') $memoryIndex=$j;
                if($memoryIndex===null || $state['artifact_memory'][$memoryIndex]['content']!==$artifacts[$i]['content']) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
                $nodeId=(string)$state['artifact_memory'][$memoryIndex]['node_id'];$found=false;
                foreach($nodes as &$node) if((string)$node['id']===$nodeId) {
                    if(($node['metadata']['workflow_key']??'')!=='episode:'.$key || ($node['metadata']['prompt']??'')!==$artifacts[$i]['content'] || ($node['metadata']['content']??'')!==$artifacts[$i]['content']) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
                    $node['metadata']['prompt']=$node['metadata']['content']=$text;
                    $node['metadata']['content_revision']=(int)($node['metadata']['content_revision']??0)+1;$found=true;
                }
                unset($node);
                if(!$found) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
                $state['artifact_memory'][$memoryIndex]['content']=$text;
                $content['stage_artifacts'][$i]['content']=$text;$restored++;
            }
            $state['state_revision']=(int)$state['state_revision']+1;$settings['workflow_state']=$state;
            $json=static fn($v)=>json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            Db::name(GraphService::TABLE)->where($scope+['id'=>$canvas])->update(['nodes_json'=>$json($nodes),'graph_revision'=>(int)$graph['graph_revision']+1,'update_time'=>time()]);
            Db::name(ConversationStore::PREFIX.'thread')->where($scope+['id'=>$thread])->update(['settings_json'=>$json($settings)]);
            Db::name(ConversationStore::PREFIX.'message')->where($scope+['id'=>$message['id']])->update(['content_json'=>$json($content)]);
            return ['changed'=>true,'restored_artifacts'=>$restored,'run_id'=>(int)$binding['run_id'],'characters'=>mb_strlen($new)];
        });
    }
}
