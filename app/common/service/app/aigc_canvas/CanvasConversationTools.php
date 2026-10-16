<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope,AgentStore,AgentFunctionTool};
use app\common\service\open_platform\OpenAssets;
use think\facade\Db;

/** Page-bound graph operations, shared by the conversation worker and result collector. */
final class CanvasConversationTools
{
    public static function tools(array $run): array
    {
        if(!AgentWorkspaceScope::fromRun($run))return [];
        $schema=['type'=>'object','additionalProperties'=>false,'required'=>['action'],'properties'=>[
            'action'=>['type'=>'string','enum'=>['read_page','add_node','update_prompt','move_node','connect_nodes','group_nodes']],
            'node_id'=>['type'=>'string'], 'target_id'=>['type'=>'string'],
            'node_ids'=>['type'=>'array','maxItems'=>100,'items'=>['type'=>'string']],
            'type'=>['type'=>'string','enum'=>['text','image','video','audio']],
            'title'=>['type'=>'string','maxLength'=>120], 'content'=>['type'=>'string','maxLength'=>10000],
            'x'=>['type'=>'number','minimum'=>-100000,'maximum'=>100000],
            'y'=>['type'=>'number','minimum'=>-100000,'maximum'=>100000],
        ]];
        return [new AgentFunctionTool('edit_canvas_page','读取当前页面，按用户要求新增节点、编辑提示词、移动、连接或分组。只操作当前绑定页面；新增节点不会调用生成模型。生成作品请使用 plan_creation，成功结果自动放入此页面。不得声称已查看没有实际读取的图片。',$schema,fn(array $p)=>self::operate($run,$p),16)];
    }
    public static function operate(array $run,array $input): array
    {
        return self::withPage($run,function(array &$nodes,array &$edges,array &$page)use($run,$input){
            $action=(string)($input['action']??'');
            $ids=$page['nodeIds'];$index=array_column($nodes,null,'id');
            if($action==='read_page')return ['page'=>['id'=>$page['id'],'name'=>$page['name']],'nodes'=>array_map(static function($id)use($index){$n=$index[$id]??[];return ['id'=>$id,'type'=>$n['type']??'','title'=>$n['title']??'','position'=>$n['position']??[],'prompt'=>$n['metadata']['prompt']??'','content'=>mb_substr((string)($n['metadata']['content']??''),0,3000),'file_id'=>$n['metadata']['agentFileId']??''];},array_slice($ids,0,100)),'truncated'=>count($ids)>100];
            if($action==='add_node'){
                $type=(string)($input['type']??'text');if(!in_array($type,['text','image','video','audio'],true))throw new \InvalidArgumentException('节点类型无效');
                $id='agent_node_'.substr(hash('sha256',AgentStore::encode([$run['id'],$input])),0,24);
                if(isset($index[$id]))return ['node_id'=>$id,'saved'=>true];
                $content=mb_substr((string)($input['content']??''),0,10000);
                $nodes[]=self::node($id,$type,(string)($input['title']??'新节点'),['prompt'=>$content,'content'=>$type==='text'?$content:''],count($ids),$input);
                $page['nodeIds'][]=$id;return ['node_id'=>$id,'saved'=>true];
            }
            $id=(string)($input['node_id']??'');
            if($action==='group_nodes'){
                $members=array_values(array_unique((array)($input['node_ids']??[])));
                if(count($members)<2||array_diff($members,$ids))throw new \InvalidArgumentException('请选择当前页面的至少两个节点');
                $group='group_'.substr(hash('sha256',AgentStore::encode([$run['id'],$members])),0,20);
                foreach($nodes as &$n)if(in_array($n['id'],$members,true))$n['metadata']['agentGroupId']=$group;
                return ['group_id'=>$group,'saved'=>true];
            }
            if(!in_array($id,$ids,true)||!isset($index[$id]))throw new \InvalidArgumentException('节点不属于当前页面');
            if($action==='connect_nodes'){
                $target=(string)($input['target_id']??'');
                if($id===$target||!in_array($target,$ids,true)||!isset($index[$target]))throw new \InvalidArgumentException('请选择当前页面不同的两个节点');
                $edgeId='agent_edge_'.substr(hash('sha256',$id.':'.$target),0,24);
                foreach($edges as $edge)if(($edge['fromNodeId']??$edge['source']??'')===$id&&($edge['toNodeId']??$edge['target']??'')===$target)return ['saved'=>true];
                $edges[]=['id'=>$edgeId,'fromNodeId'=>$id,'toNodeId'=>$target];return ['saved'=>true];
            }
            foreach($nodes as &$node)if($node['id']===$id){
                if($action==='update_prompt')$node['metadata']['prompt']=mb_substr((string)($input['content']??''),0,10000);
                elseif($action==='move_node')$node['position']=['x'=>max(-100000,min(100000,(float)($input['x']??0))),'y'=>max(-100000,min(100000,(float)($input['y']??0)))];
                else throw new \InvalidArgumentException('不支持此页面操作');
            }
            return ['node_id'=>$id,'saved'=>true];
        },($input['action']??'')!=='read_page');
    }
    public static function artifact(array $run,int $artifactId,string $file,string $kind,string $title): void
    {
        if(!AgentWorkspaceScope::fromRun($run)||!in_array($kind,['image','video','audio'],true))return;
        $asset=OpenAssets::detail((int)$run['tenant_id'],(int)$run['user_id'],$file);
        self::withPage($run,function(array &$nodes,array &$edges,array &$page)use($run,$artifactId,$file,$kind,$title,$asset){
            $artifact=AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('id',$artifactId);
            $data=AgentStore::json((clone $artifact)->value('data'));
            if(!empty($data['canvas_node_id']))return [];
            $id='agent_artifact_'.$artifactId;
            foreach($nodes as $node)if($node['id']===$id)return [];
            $nodes[]=self::node($id,$kind,$title,['status'=>'success','agentFileId'=>$file,'agentArtifactId'=>$artifactId,'url'=>$asset['url'],'image'=>$kind==='image'?$asset['url']:'','content'=>$asset['url'],'source'=>'agent','generatedAt'=>time()*1000],count($page['nodeIds']));
            $page['nodeIds'][]=$id;
            $artifact->update(['data'=>AgentStore::encode($data+['canvas_node_id'=>$id])]);
            return [];
        });
    }
    private static function node(string $id,string $type,string $title,array $metadata,int $count,array $position=[]): array
    {
        return ['id'=>$id,'type'=>$type,'title'=>mb_substr($title,0,120),'position'=>['x'=>(float)($position['x']??(($count%3)*400)),'y'=>(float)($position['y']??(floor($count/3)*440))],'width'=>340,'height'=>320,'metadata'=>$metadata+['status'=>'idle','prompt'=>'','content'=>'']];
    }
    private static function withPage(array $run,callable $call,bool $write=true): array
    {
        $scope=AgentWorkspaceScope::fromRun($run);
        if(($scope['app']??'')!=='aigc_canvas')throw new \InvalidArgumentException('此会话不能编辑画布');
        return Db::transaction(static function()use($run,$scope,$call,$write){
            $project=AigcCanvasProject::where(['tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'id'=>$scope['project_id'],'delete_time'=>0])->lock(true)->findOrEmpty();
            if($project->isEmpty())throw new \InvalidArgumentException('项目已删除');
            $nodes=AgentStore::json($project['nodes_json']);$edges=AgentStore::json($project['edges_json']);$viewport=AgentStore::json($project['viewport_json']);
            $pages=$viewport['pages']??[['id'=>'canvas_1','name'=>'页面 1','nodeIds'=>array_column($nodes,'id'),'viewport'=>$viewport]];
            $pageIndex=array_search($scope['page_id'],array_column($pages,'id'),true);
            if($pageIndex===false)throw new \InvalidArgumentException('目标页面已删除，结果仍保留在会话中');
            $before=AgentStore::encode([$nodes,$edges,$pages]);
            $result=$call($nodes,$edges,$pages[$pageIndex]);
            if($write&&$before!==AgentStore::encode([$nodes,$edges,$pages])){
                $viewport['pages']=$pages;$viewport['currentCanvasId']??=$pages[0]['id'];
                $viewport['agentRevision']=(int)($viewport['agentRevision']??0)+1;
                $project->save(['nodes_json'=>$nodes,'edges_json'=>$edges,'viewport_json'=>$viewport,'update_time'=>time()]);
                AgentStore::event($run,'canvas.updated',['project_id'=>$scope['project_id'],'page_id'=>$scope['page_id'],'revision'=>$viewport['agentRevision']]);
            }
            return $result;
        });
    }
}
