<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_canvas;

use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope,AgentStore,AgentExecutionService};
use think\facade\Db;

final class CanvasConversationService
{
    /** Project + first message commit together. A network retry reuses the same run. */
    public static function start(int $tenant,int $user,array $params): array
    {
        $request=(string)($params['request_id']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D',$request))throw new \InvalidArgumentException('请求标识无效');
        $params['session_id']=0;
        $resume=static function(array $run)use($tenant,$user,$params):array{
            $scope=AgentWorkspaceScope::fromRun($run);
            if(($scope['app']??'')!=='aigc_canvas'||empty(AgentStore::json($run['preferences'])['_canvas_entry']))throw new \InvalidArgumentException('此请求不属于画布创作入口');
            AgentWorkspaceScope::canvas($tenant,$user,$scope);
            $detail=AgentWorkspaceScope::within($scope,fn()=>AgentExecutionService::submit($tenant,$user,$params));
            return ['project_id'=>$scope['project_id'],'page_id'=>$scope['page_id'],'session_id'=>$detail['session_id'],'run_id'=>$detail['id']];
        };
        $existing=AgentStore::owner('run',$tenant,$user)->where('request_id',$request)->find();
        if($existing)return $resume($existing);
        try{
            return Db::transaction(static function()use($tenant,$user,$params){
                $content=trim((string)($params['content']??''));
                if($content===''||mb_strlen($content)>10000)throw new \InvalidArgumentException('请输入1至10000字的请求');
                $project=AigcCanvasService::createProject($tenant,$user,['name'=>mb_substr(preg_replace('/\s+/u',' ',$content),0,30),'nodes'=>[],'edges'=>[],
                    'viewport'=>['x'=>80,'y'=>80,'zoom'=>1,'currentCanvasId'=>'canvas_1','pages'=>[['id'=>'canvas_1','name'=>'页面 1','nodeIds'=>[],'viewport'=>['x'=>80,'y'=>80,'k'=>1]]]]]);
                $scope=['app'=>'aigc_canvas','project_id'=>(int)$project['id'],'page_id'=>'canvas_1'];
                $run=AgentWorkspaceScope::within($scope,fn()=>AgentExecutionService::submit($tenant,$user,$params));
                $stored=AgentStore::run($tenant,$user,(int)$run['id']);
                AgentStore::owner('run',$tenant,$user)->where('id',$run['id'])->update(['preferences'=>AgentStore::encode(AgentStore::json($stored['preferences'])+['_canvas_entry'=>true])]);
                return ['project_id'=>$project['id'],'page_id'=>'canvas_1','session_id'=>$run['session_id'],'run_id'=>$run['id']];
            });
        }catch(\Throwable $error){
            // A racing insert loses the unique request-id constraint; its project rolls back.
            $existing=AgentStore::owner('run',$tenant,$user)->where('request_id',$request)->find();
            if($existing)return $resume($existing);
            throw $error;
        }
    }
}
