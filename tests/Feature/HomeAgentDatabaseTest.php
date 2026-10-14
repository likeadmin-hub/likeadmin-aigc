<?php
// Local transactional fixtures only. Never runs tick(), a provider, or any creative submission.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentCapabilityCatalog as C,AgentToolContext as P};
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');verify(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
$request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['user_id'=>1,'tenant_id'=>1];
$balances=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$balances();
Db::startTrans();try{
 $models=C::models(1);verify(!empty($models['text']['models']),'Need local compatible model fixture');
 S::preferences(1,1,['mode'=>'ask','models'=>['text'=>$models['text']['models'][0]['code'],'image'=>'market_image_model:234']]);
 $params=['request_id'=>'home_test_'.bin2hex(random_bytes(12)),'content'=>'离线验收：仅保存，不执行模型'];
 $one=E::submit(1,1,$params);$two=E::submit(1,1,$params);verify($one['phase']==='chat','initial run must not show submitted generation');verify($one['id']===$two['id'],'submit replay created a new run');
 try{E::submit(1,1,array_replace($params,['content'=>'changed']));throw new RuntimeException('replay mismatch accepted');}catch(InvalidArgumentException){}
 foreach([[2,1],[1,2]] as [$t,$u]){try{E::detail($t,$u,(int)$one['id']);throw new RuntimeException('foreign run readable');}catch(InvalidArgumentException){}}
 $run=S::run(1,1,(int)$one['id']);$quote=C::prepare($run,'aigc_image',['prompt'=>'offline test']);$again=C::prepare($run,'aigc_image',['prompt'=>'offline test']);verify(P::priceFingerprint($quote['quote'])===P::priceFingerprint($again['quote']),'random IV causes false price change');
 P::within($quote,static function()use($quote){$image=P::image(1,1);verify($image['selection']['channel']['code']==='market_image_model:234','selected model changed');try{P::image(1,2);throw new RuntimeException('foreign price snapshot readable');}catch(RuntimeException $e){verify($e->getMessage()!=='foreign price snapshot readable','snapshot leaked');}return [];});verify(P::image(1,1)===null,'context leaked');
 S::table('run')->where('id',$one['id'])->update(['status'=>'awaiting_confirmation','approval_hash'=>'v1']);
 try{E::confirm(1,1,(int)$one['id'],'old');throw new RuntimeException('stale confirmation accepted');}catch(InvalidArgumentException){}
 $confirmed=E::confirm(1,1,(int)$one['id'],'v1');verify($confirmed['status']==='queued'&&$confirmed['phase']==='tools','confirmed run must expose generation queue');verify(E::confirm(1,1,(int)$one['id'],'v1')['status']==='queued','confirmation replay failed');
 verify(E::stop(1,1,(int)$one['id'])['status']==='canceled','queued stop not terminal');
 verify($balances()===$before,'Planning or confirmation charged a wallet');
 echo "Home Agent database contracts passed; no provider called\n";
}finally{Db::rollback();}
verify($balances()===$before,'Rollback did not preserve balances');
