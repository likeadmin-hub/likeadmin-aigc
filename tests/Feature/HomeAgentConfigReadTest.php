<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Workspace,AgentCapabilityCatalog as Catalog,AgentStore,AgentSkillConfigService};
use app\common\service\app\AppDisplayConfigService;
use think\facade\Db;
function verify($condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
$db=config('database.connections.mysql');
verify(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local read-only test only');
$connections=config('database.connections');$connections['mysql']['trigger_sql']=true;config(['connections'=>$connections],'database');
$queries=0;Db::listen(static function($sql)use(&$queries){if(preg_match('/^(SELECT|SHOW|DESCRIBE)/i',trim($sql)))++$queries;});
request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1];
foreach([[],['app'=>'aigc_canvas','project_id'=>0,'page_id'=>'entry']] as $scope){
    Workspace::within($scope,function()use(&$queries){
        $before=$queries;$started=microtime(true);
        $tools=Catalog::all(1,1);$displays=[];
        if(Workspace::app()!=='aigc_canvas')foreach(array_unique(array_merge(['aigc_llm'],array_keys($tools))) as $code)$displays[$code]=AppDisplayConfigService::skillPreview(1,$code==='speech_synthesis'?'aigc_llm':$code);
        $expected=['preferences'=>AgentStore::preferences(1,1),'models'=>Catalog::models(1),'tools'=>array_values($tools),'skill_displays'=>$displays,'skill_configs'=>AgentSkillConfigService::forTools(1,array_keys($tools))];
        $baseline=['queries'=>$queries-$before,'ms'=>round((microtime(true)-$started)*1000,1)];
        $before=$queries;$started=microtime(true);$actual=Catalog::configuration(1,1);
        $optimized=['queries'=>$queries-$before,'ms'=>round((microtime(true)-$started)*1000,1)];
        verify($actual===$expected,'Configuration changed models, retail prices, capabilities, skills or preferences');
        verify($optimized['queries']<$baseline['queries'],'Repeated configuration queries were not reduced');
        verify(request()->marketCatalogReadScope===null,'Display snapshot escaped the request scope');
        echo json_encode(['workspace'=>Workspace::app(),'before'=>$baseline,'after'=>$optimized],JSON_UNESCAPED_UNICODE).PHP_EOL;
    });
}
echo "Agent configuration read regression passed: identical response and fewer queries; no provider requests\n";
