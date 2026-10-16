<?php
// Local rollback fixtures: no provider requests, paid tasks or physical files.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Scope,AgentStore as S,AgentArtifactService};
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasConversationTools as Tools};
use app\common\model\app\aigc_llm\AigcLlmSession;
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
 // Scope filters preserve works belonging to deleted sessions and legacy orphan records.
 foreach([$sessions[0],$sessions[1],$sessions[2],1999999998] as $i=>$session)S::table('artifact')->insert(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$session,'run_id'=>$run['id'],'step_id'=>0,'identity_hash'=>hash('sha256','scope'.$i),'title'=>'fixture','kind'=>'image','data'=>'{}','create_time'=>time()]);
 Db::name('aigc_llm_session')->where('id',$sessions[1])->update(['delete_time'=>time()]);
 check(Scope::artifacts(S::owner('artifact',$t,$u),$t,$u)->count()===2,'Home lost legacy works or gained canvas works');
 Scope::within($scope,function()use($t,$u){check(Scope::artifacts(S::owner('artifact',$t,$u),$t,$u)->count()===1,'Deleting a session hid its works');});
 echo "Canvas conversation scope passed: tenant/user/page isolation, scope restoration, idempotent graph edits, stale-save protection and retained works; rolled back\n";
}finally{Db::rollback();}
