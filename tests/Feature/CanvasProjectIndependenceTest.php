<?php
// Isolated tables and stubbed membership/provider boundaries; never calls a provider.
namespace app\common\service\app {
    final class AppAccessService { public static function assertTenantCanUse(...$args) { return null; } }
}
namespace app\common\service\app\aigc_llm\agent {
    final class AgentCapabilityCatalog {
        public static array $queried=[];
        public static function query(array $run,string $code,int $id): array {
            self::$queried[]=$id;return ['task_id'=>$id,'status'=>'running','results'=>[]];
        }
        public static function prepare(...$args): array { throw new \RuntimeException('Unexpected preparation'); }
        public static function submit(...$args): array { throw new \RuntimeException('Provider submission forbidden'); }
    }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().'\n'.$e->getTraceAsString());exit(1);});
$db=config('database.connections.mysql');
if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new RuntimeException('Local isolated tables only');
$cfg=\think\facade\Config::get('database');$old=$cfg['connections']['mysql']['prefix'];$audit='auditcanvas_'.getmypid().'_';
$tables=['aigc_canvas_project','aigc_canvas_run','aigc_canvas_asset','aigc_canvas_project_compat_backup','aigc_canvas_agent_thread','aigc_canvas_agent_message','aigc_llm_session','aigc_llm_message','aigc_llm_usage','aigc_llm_agent_run','aigc_llm_agent_step','aigc_llm_agent_event','aigc_llm_agent_artifact','aigc_llm_agent_preference','aigc_llm_agent_context'];
foreach($tables as $table)\think\facade\Db::execute("CREATE TABLE `{$audit}{$table}` LIKE `{$old}{$table}`");
$cfg['connections']['mysql']['prefix']=$audit;\think\facade\Config::set($cfg,'database');\think\facade\Db::connect(null,true);
if(\think\facade\Db::connect()->getConfig('prefix')!==$audit)throw new RuntimeException('isolation failed');
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Scope,AgentStore as S,AgentArtifactService};
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasConversationTools as Tools};
use app\common\model\app\aigc_llm\AigcLlmSession;
try {

// Local rollback fixtures: no provider requests, paid tasks or physical files.

function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejected(callable $call){try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected scope rejection');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try{
 $t=1999999892;$u=$t;
 $project=Canvas::createProject($t,$u,['name'=>'scope fixture','nodes'=>[['id'=>'other','type'=>'text','metadata'=>[]]],'viewport'=>['pages'=>[['id'=>'p1','name'=>'One','nodeIds'=>[]],['id'=>'p2','name'=>'Two','nodeIds'=>['other']]],'currentCanvasId'=>'p2']]);
 $scope=Scope::canvas($t,$u,['project_id'=>$project['id'],'page_id'=>'p1']);
 rejected(fn()=>Scope::canvas($t+1,$u,['project_id'=>$project['id'],'page_id'=>'p1']));
 rejected(fn()=>Scope::canvas($t,$u+1,['project_id'=>$project['id'],'page_id'=>'p1']));
 rejected(fn()=>Scope::canvas($t,$u,['project_id'=>$project['id'],'page_id'=>'missing']));
 $sessions=[];
 foreach(['home',Scope::key($scope),'canvas:'.$project['id'].':p2'] as $key)$sessions[]=AigcLlmSession::create(['tenant_id'=>$t,'user_id'=>$u,'title'=>'scope fixture','workspace_key'=>$key,'status'=>'idle','delete_time'=>0,'create_time'=>time(),'update_time'=>time()])->id;
 check(Scope::sessions($t,$u)->column('id')==[$sessions[0]],'Home included canvas sessions');
 Scope::within($scope,function()use($t,$u,$sessions){check(Scope::sessions($t,$u)->column('id')==[$sessions[1]],'Page session boundary failed');rejected(fn()=>Scope::assertSession($t,$u,$sessions[0]));rejected(fn()=>Scope::assertSession($t,$u,$sessions[2]));});
 try{Scope::within($scope,fn()=>throw new RuntimeException('fixture'));}catch(RuntimeException){}
 check(Scope::key()==='home','Scope leaked after failure');
 $run=['id'=>1999999892,'tenant_id'=>$t,'user_id'=>$u,'preferences'=>S::encode(['_workspace'=>$scope])];
 rejected(fn()=>Tools::operate($run,['action'=>'move_node','node_id'=>'other','x'=>1,'y'=>2]));
 rejected(fn()=>Tools::operate(array_replace($run,['preferences'=>'{}']),['action'=>'read_page']));
 $a=Tools::operate($run,['action'=>'add_node','type'=>'text','content'=>'hello']);
 $again=Tools::operate($run,['action'=>'add_node','type'=>'text','content'=>'hello']);check($a==$again,'Repeated add created a second node');
 $b=Tools::operate($run,['action'=>'add_node','type'=>'image']);
 Tools::operate($run,['action'=>'connect_nodes','node_id'=>$a['node_id'],'target_id'=>$b['node_id']]);
 Tools::operate($run,['action'=>'connect_nodes','node_id'=>$a['node_id'],'target_id'=>$b['node_id']]);
 $now=Canvas::projectDetail($t,$u,$project['id']);check(count($now['nodes'])===3&&count($now['edges'])===1,'Graph operations duplicated nodes or edges');
 check($now['viewport']['pages'][1]['nodeIds']===['other']&&$now['viewport']['currentCanvasId']==='p2','Agent modified another page or current selection');
 check($now['edges'][0]['fromNodeId']===$a['node_id'],'Graph edge contract changed');
 try{Canvas::saveProject($t,$u,['id'=>$project['id'],'nodes'=>[],'viewport'=>$project['viewport']]);throw new LogicException('Stale canvas overwrote Agent graph');}catch(LogicException $e){throw $e;}catch(Exception $e){check(str_contains($e->getMessage(),'Agent'),'Unexpected save failure');}
// Run ownership and controller parameter-source audit.
$second=Canvas::createProject($t,$u,['name'=>'other project','nodes'=>[],'viewport'=>['pages'=>[['id'=>'p1','name'=>'One','nodeIds'=>[]]],'currentCanvasId'=>'p1']]);
$secondScope=Scope::canvas($t,$u,['project_id'=>$second['id'],'page_id'=>'p1']);
$otherSession=AigcLlmSession::create(['tenant_id'=>$t,'user_id'=>$u,'title'=>'other session','workspace_key'=>Scope::key($secondScope),'status'=>'idle','delete_time'=>0,'create_time'=>time(),'update_time'=>time()])->id;
$runIds=[];
foreach([[$scope,$sessions[1]],[$secondScope,$otherSession]] as [$binding,$sid]){
 $rid=S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$sid,'message_id'=>0,'request_id'=>'isolation-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>'isolation fixture','preferences'=>S::encode(['_workspace'=>$binding]),'attachments'=>'[]','status'=>'running','phase'=>'tools','reply'=>'','lease_token'=>'audit-lease','lease_until'=>time()+1500,'create_time'=>time(),'update_time'=>time()]);
 $runIds[]=$rid;S::event(S::run($t,$u,$rid),'audit.marker',['project_id'=>$binding['project_id']]);
}
Scope::within($scope,fn()=>rejected(fn()=>Scope::assertRun($t,$u,$runIds[1])));
$originalRequest=app()->request;
$request=new \app\Request();$request->withServer(['REQUEST_METHOD'=>'POST']);$request->tenantId=$t;$request->userId=$u;$request->userInfo=['tenant_id'=>$t,'user_id'=>$u];$request->setAction('events');
$request->withGet(['project_id'=>$project['id'],'page_id'=>'p1','run_id'=>$runIds[1]]);$request->withPost([]);app()->instance('request',$request);
$controller=new \app\api\controller\app\aigc_canvas\ConversationController(app());
$normal=$controller->events()->getData();check((int)$normal['code']!==1,'normal foreign project run rejected');
$request->withPost(['run_id'=>0]);
$mixed=$controller->events()->getData();
check((int)$mixed['code']!==1,'POST zero bypassed run validation');
$request->withPost(['run_id'=>$runIds[0]]);
check((int)$controller->events()->getData()['code']!==1,'Different valid POST run bypassed GET run validation');
$request->withGet(['project_id'=>$project['id'],'page_id'=>'p1','run_id'=>$runIds[0]]);$request->withPost(['run_id'=>$runIds[0]]);
check((int)$controller->events()->getData()['code']===1,'Matching query/body identifiers rejected');
$request->withPost(['project_id'=>0]);check((int)$controller->events()->getData()['code']!==1,'Conflicting project identifier accepted');
echo "PASS request ambiguity and cross-project run rejection\n";
app()->instance('request',$originalRequest);
// All canvas projects share this user's app preference row.
Scope::within($scope,fn()=>S::preferences($t,$u,['mode'=>'auto','models'=>[]]));
check(Scope::within($secondScope,fn()=>S::preferences($t,$u))['mode']==='ask','Project preferences leaked');
check(Scope::within(array_replace($scope,['page_id'=>'p2']),fn()=>S::preferences($t,$u))['mode']==='auto','Same-project pages lost shared preferences');
Scope::within($secondScope,fn()=>S::preferences($t,$u,['mode'=>'auto','models'=>['image'=>'project-b']]));
check((Scope::within($scope,fn()=>S::preferences($t,$u))['models']['image']??'')==='','Project B changed project A');
echo "PASS independent project preferences and same-project pages\n";
check(S::preferences($t,$u)['mode']==='ask','home preferences remain independent from canvas');
// Delete a project, then call the real no-I/O admission boundary.
$doomed=S::run($t,$u,$runIds[1]);
$prepared=['capability'=>['code'=>'aigc_image','version'=>'audit'],'quote'=>['user'=>10,'tenant'=>1,'snapshot'=>[]]];
$stepId=S::table('step')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'run_id'=>$doomed['id'],'step_key'=>hash('sha256','audit'),'title'=>'audit','tool_code'=>'aigc_image','prepared'=>S::encode($prepared),'result'=>'','status'=>'proposed','create_time'=>time(),'update_time'=>time()]);
Canvas::deleteProject($t,$u,$second['id']);
$step=S::owner('step',$t,$u)->where('id',$stepId)->find();
$status=(new ReflectionMethod(\app\common\service\app\aigc_llm\agent\AgentExecutionService::class,'admitStep'))->invoke(null,$doomed,$step,$prepared);
check($status==='canceled','Deleted project admitted unsubmitted step');
check((int)S::run($t,$u,$doomed['id'])['cancel_requested']===1,'Deletion did not request cancellation');
$known=S::table('step')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'run_id'=>$doomed['id'],'step_key'=>hash('sha256','known'),'title'=>'known','tool_code'=>'aigc_image','prepared'=>S::encode($prepared),'result'=>'','status'=>'running','task_id'=>765432,'create_time'=>time(),'update_time'=>time()]);
(new ReflectionMethod(\app\common\service\app\aigc_llm\agent\AgentExecutionService::class,'executeSteps'))->invoke(null,$doomed);
check(\app\common\service\app\aigc_llm\agent\AgentCapabilityCatalog::$queried===[765432],'Deletion lost submitted task polling');
check(S::run($t,$u,$doomed['id'])['status']==='waiting_tasks','Deleted-project submitted task no longer retained');
rejected(fn()=>(new ReflectionMethod(\app\common\service\app\aigc_llm\agent\AgentExecutionService::class,'checkpoint'))->invoke(null,$doomed));
rejected(fn()=>Tools::operate($doomed,['action'=>'read_page']));
echo "PASS deleted project blocks admission, preserves polling and rejects graph edits\n";
// Removing just a page also closes admission, even without a project-deletion request.
$first=S::run($t,$u,$runIds[0]);
Db::name('aigc_canvas_project')->where('id',$project['id'])->update(['viewport_json'=>S::encode(['pages'=>[['id'=>'p2','name'=>'Two','nodeIds'=>['other']]]])]);
$stepId=S::table('step')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'run_id'=>$first['id'],'step_key'=>hash('sha256','page'),'title'=>'page','tool_code'=>'aigc_image','prepared'=>S::encode($prepared),'result'=>'','status'=>'proposed','create_time'=>time(),'update_time'=>time()]);
$step=S::owner('step',$t,$u)->where('id',$stepId)->find();
check((new ReflectionMethod(\app\common\service\app\aigc_llm\agent\AgentExecutionService::class,'admitStep'))->invoke(null,$first,$step,$prepared)==='canceled','Deleted page admitted new step');
echo "PASS deleted page blocks admission\n";
// All-tenant source migration snapshots old defaults and is safe to repeat.
$third=Canvas::createProject($t+10,$u+10,['name'=>'legacy preferences fixture','nodes'=>[],'viewport'=>[]]);
S::table('preference')->insert(['tenant_id'=>$t+10,'user_id'=>$u+10,'workspace_key'=>'aigc_canvas','preferences'=>S::encode(['mode'=>'auto','models'=>['image'=>'legacy']]),'create_time'=>time(),'update_time'=>time()]);
$sql=file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_canvas/migrations/zz_20261017_project_agent_preferences.sql');
check($sql===file_get_contents(dirname(__DIR__,2).'/upgrade/20261017_canvas_project_agent_preferences.sql'),'Upgrade parity failed');
check(str_contains(file_get_contents(dirname(__DIR__,2).'/public/install/db/like.sql'),$sql),'Fresh install parity failed');
\app\common\service\database\RuntimeMigrationPlan::build($sql);
\app\common\service\database\SqlMigrationExecutor::execute($sql,$audit);
$thirdScope=['app'=>'aigc_canvas','project_id'=>$third['id'],'page_id'=>'canvas_1'];
check(Scope::within($thirdScope,fn()=>S::preferences($t+10,$u+10))['models']['image']==='legacy','Other tenant legacy defaults lost');
Scope::within($thirdScope,fn()=>S::preferences($t+10,$u+10,['mode'=>'ask','models'=>['image'=>'new']]));
\app\common\service\database\SqlMigrationExecutor::execute($sql,$audit);
check(Scope::within($thirdScope,fn()=>S::preferences($t+10,$u+10))['models']['image']==='new','Repeat upgrade overwrote project setting');
\app\common\service\app\aigc_llm\agent\AgentWorkspaceData::clear('aigc_llm',$t+10);
check(S::owner('preference',$t+10,$u+10)->where('workspace_key','canvas:'.$third['id'])->count()===1,'Home cleanup removed project preferences');
\app\common\service\app\aigc_llm\agent\AgentWorkspaceData::clear('aigc_canvas',$t+10);
check(S::owner('preference',$t+10,$u+10)->count()===0,'Canvas cleanup left project preferences');
echo "PASS all-tenant upgrade, repeated upgrade and scoped cleanup\n";
}finally{Db::rollback();}
}finally{foreach(array_reverse($tables) as $table)\think\facade\Db::execute("DROP TABLE IF EXISTS `{$audit}{$table}`");}

}
