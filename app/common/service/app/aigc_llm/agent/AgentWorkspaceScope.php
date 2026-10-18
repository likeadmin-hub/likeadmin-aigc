<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\app\aigc_llm\AigcLlmSession;
use app\common\service\app\aigc_canvas\AigcCanvasService;

/** Server-selected scope. Never accept a workspace snapshot from the model/client. */
final class AgentWorkspaceScope
{
    private static array $current = [];
    public static function filter(string $table): array
    {
        // Source may reload before an additive migration reaches this database.
        // Historical home traffic keeps using its existing schema in that window.
        $fields=\think\facade\Db::name($table)->getFields();
        if(!isset($fields['workspace_key'])){
            if(self::$current)throw new \InvalidArgumentException('画布 Agent 正在升级，请稍后重试');
            return [];
        }
        return ['workspace_key'=>$table==='aigc_llm_session'?self::key():self::preferenceKey()];
    }
    public static function current(): array { return self::$current; }
    public static function key(?array $scope=null): string
    {
        $scope ??= self::$current;
        return ($scope['app']??'')==='aigc_canvas'
            ? 'canvas:'.(int)$scope['project_id'].':'.(string)$scope['page_id'] : 'home';
    }
    public static function preferenceKey(): string
    {
        return self::app()==='aigc_canvas' && (int)(self::$current['project_id']??0)>0
            ? 'canvas:'.(int)self::$current['project_id'] : self::app();
    }
    /** Lock the project before session/run locks; completed results may outlive deletion. */
    public static function activeRunWorkspace(array $run,bool $lock=false): bool
    {
        $scope=self::fromRun($run);
        if(($scope['app']??'')!=='aigc_canvas')return true;
        $query=\think\facade\Db::name('aigc_canvas_project')->where(['id'=>(int)($scope['project_id']??0),'tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id']]);
        if($lock)$query->lock(true);
        $project=$query->find();
        if(!$project || (int)$project['delete_time']>0)return false;
        $viewport=AgentStore::json($project['viewport_json']);
        return in_array($scope['page_id']??'',array_column($viewport['pages']??[['id'=>'canvas_1']],'id'),true);
    }
    public static function app(): string { return self::$current['app']??'aigc_llm'; }
    public static function within(array $scope,callable $call)
    {
        $previous=self::$current;self::$current=$scope;
        try { return $call(); } finally { self::$current=$previous; }
    }
    public static function fromRun(array $run): array { return AgentStore::json($run['preferences'])['_workspace']??[]; }
    public static function canvas(int $tenant,int $user,array $params): array
    {
        $id=(int)($params['project_id']??0);$page=(string)($params['page_id']??'');
        if($id<1||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$page))throw new \InvalidArgumentException('请选择项目和页面');
        try { $project=AigcCanvasService::projectDetail($tenant,$user,$id); }
        catch(\Exception $e) { throw new \InvalidArgumentException('项目不存在或不可访问'); }
        $pages=$project['viewport']['pages']??[['id'=>'canvas_1']];
        if(!in_array($page,array_column($pages,'id'),true))throw new \InvalidArgumentException('页面不存在，请重新选择');
        return ['app'=>'aigc_canvas','project_id'=>$id,'page_id'=>$page];
    }
    public static function sessions(int $tenant,int $user)
    {
        return AigcLlmSession::where(['tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0]+self::filter('aigc_llm_session'));
    }
    public static function artifacts($query,int $tenant,int $user)
    {
        $filter=self::filter('aigc_llm_session');
        if(!$filter)return $query;
        // Works survive deletion of their conversation, including legacy orphan rows.
        $sessions=\think\facade\Db::name('aigc_llm_session')->where(['tenant_id'=>$tenant,'user_id'=>$user]);
        if(self::key()==='home'){
            $ids=$sessions->where('workspace_key','<>','home')->column('id');
            return $ids?$query->whereNotIn('session_id',$ids):$query;
        }
        return $query->whereIn('session_id',$sessions->where($filter)->column('id')?:[0]);
    }
    public static function assertSession(int $tenant,int $user,int $id): void
    {
        if($id<1||!self::sessions($tenant,$user)->where('id',$id)->find())throw new \InvalidArgumentException('会话不属于当前工作区');
    }
    public static function assertRun(int $tenant,int $user,int $id): void
    {
        $run=AgentStore::run($tenant,$user,$id);
        if(self::key(self::fromRun($run))!==self::key())throw new \InvalidArgumentException('任务不属于当前工作区');
        self::assertSession($tenant,$user,(int)$run['session_id']);
    }
}
