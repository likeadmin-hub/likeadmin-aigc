<?php
// Local transactional fixtures: no worker tick, provider request or paid generation.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
require dirname(__DIR__).'/Support/HomeAgentFixture.php';
use think\facade\Db;
use app\common\service\app\aigc_canvas\CanvasConversationService as C;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as W,AgentStore as S};
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
request()->tenantId=1;request()->userId=1;request()->userInfo=['user_id'=>1,'tenant_id'=>1];
$balances=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$balances();
Db::startTrans();try{
 $count=fn()=>Db::name('aigc_canvas_project')->where(['tenant_id'=>1,'user_id'=>1])->count();$n=$count();
 $home=W::sessions(1,1)->column('id');$homePrefs=S::preferences(1,1);
 $params=['request_id'=>'canvas_test_'.bin2hex(random_bytes(12)),'content'=>'离线创建项目验收，不执行模型','preferences'=>HomeAgentFixture::preferences()];
 $one=C::start(1,1,$params);$two=C::start(1,1,$params);
 check($one===$two&&$count()===$n+1,'retry duplicated project or conversation');
 check(W::sessions(1,1)->column('id')===$home,'canvas session leaked into AIGC');
 check(S::preferences(1,1)===$homePrefs,'home preferences changed');
 $scope=W::canvas(1,1,['project_id'=>$one['project_id'],'page_id'=>$one['page_id']]);
 W::within($scope,function()use($one){check(W::sessions(1,1)->where('id',$one['session_id'])->count()===1,'canvas session missing');});
 try{C::start(1,1,array_replace($params,['content'=>'different']));throw new RuntimeException('mismatched retry accepted');}catch(InvalidArgumentException){}
 try{C::start(1,1,array_replace($params,['request_id'=>'canvas_test_'.bin2hex(random_bytes(12)),'attachments'=>['missing-file']]));throw new RuntimeException('invalid attachment accepted');}catch(\app\common\service\open_platform\OpenException){}
 check($count()===$n+1,'failed launch left an empty project');
 check($balances()===$before,'queued entry charged a wallet');
 echo "Canvas launch: atomic project, idempotent retry, scope isolation and rollback passed; no provider called\n";
}finally{Db::rollback();}
check($balances()===$before,'rollback changed wallet');
