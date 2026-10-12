<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\service\decorate\agent\DecorationAgentService as S;
use app\common\service\decorate\agent\DecorationAgentCatalog as C;
use app\common\service\decorate\agent\DecorationAgentOperations as O;
use app\common\service\decorate\DecorateTemplateService as T;
function assertAgent($ok,$message){if(!$ok)throw new RuntimeException($message);}
config(['enabled'=>true],'decoration_agent');request()->tenantId=1;
$baseline=Db::name('decorate_page')->where('tenant_id',1)->where('id',1)->find();
$baselineJson=json_encode($baseline);
$template=T::add(1,['name'=>'Agent isolated acceptance '.date('His'),'terminal'=>'mobile']);
$pages=T::pageLists(1,(int)$template['id'],'mobile');$page=$pages[0];
$widget=C::create('news','n');$initial=['data'=>[$widget],'meta'=>S::decode($page['draft_meta']),'name'=>'Agent测试','new_page'=>false];
$page=T::savePage(1,['template_id'=>$template['id'],'id'=>$page['id'],'terminal'=>'mobile','name'=>$initial['name'],'data'=>S::json($initial['data']),'meta'=>S::json($initial['meta'])]);
$session=S::createSession(1,1,['template_id'=>$template['id'],'page_id'=>$page['id']]);
assertAgent(strlen($session['current_hash'])===64,'session reads original hash');
try{S::session(1,2,$session['id']);throw new RuntimeException('cross admin accepted');}catch(RuntimeException $e){assertAgent(str_contains($e->getMessage(),'无权'),'cross admin isolation');}
try{S::session(2,1,$session['id']);throw new RuntimeException('cross tenant accepted');}catch(RuntimeException $e){assertAgent(str_contains($e->getMessage(),'无权'),'cross tenant isolation');}
$candidate=O::apply($initial,[['type'=>'update_component','widget_id'=>'n','fields'=>['styles.margin_top'=>30]]],['scope'=>'page'])['candidate'];
$insertCandidate=function($payload,$base,$run=0)use($session){return Db::name('decorate_agent_candidate')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>$run,'status'=>'ready','revision'=>1,'base_hash'=>S::hash($base),'base_json'=>S::json($base),'payload_json'=>S::json($payload),'changes_json'=>'[]','create_time'=>time(),'update_time'=>time()]);};
$cid=$insertCandidate($candidate,$initial);
assertAgent(S::hash(S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']])))===S::hash($initial),'candidate did not mutate draft');
$applied=S::apply(1,1,['candidate_id'=>$cid,'idempotency_key'=>'acceptance:'.$cid]);
assertAgent(S::payload($applied['page'])['data'][0]['styles']['margin_top']===30,'apply changes draft');
$replayed=S::apply(1,1,['candidate_id'=>$cid,'idempotency_key'=>'acceptance:'.$cid]);assertAgent($replayed['checkpoint_id']===$applied['checkpoint_id'],'apply idempotent');
$rolled=S::rollback(1,1,['checkpoint_id'=>$applied['checkpoint_id'],'idempotency_key'=>'undo:'.$cid]);assertAgent(S::hash(S::payload($rolled['page']))===S::hash($initial),'rollback restores');
$again=S::rollback(1,1,['checkpoint_id'=>$applied['checkpoint_id'],'idempotency_key'=>'undo:'.$cid]);assertAgent($again['checkpoint_id']===$rolled['checkpoint_id'],'rollback idempotent');
$new=$candidate;$new['new_page']=true;$new['name']='Agent新页面';$nid=$insertCandidate($new,$initial);
$created=S::apply(1,1,['candidate_id'=>$nid,'idempotency_key'=>'new:'.$nid]);assertAgent($created['page']['id']!==$page['id'],'new page created');
$undone=S::rollback(1,1,['checkpoint_id'=>$created['checkpoint_id'],'idempotency_key'=>'undo_new:'.$nid]);assertAgent($undone['deleted']&&$undone['page']['id']===$page['id'],'new page rollback restores origin');
assertAgent(S::session(1,1,$session['id'])['page_id']===$page['id'],'new page session origin restored');
$redo=S::apply(1,1,['candidate_id'=>$nid,'idempotency_key'=>'redo_new:'.$nid]);assertAgent($redo['page']['id']!==$created['page']['id'],'new page redo recreates');
assertAgent(json_encode(Db::name('decorate_page')->where('tenant_id',1)->where('id',1)->find())===$baselineJson,'existing page untouched');
echo json_encode(['passed'=>13,'template_id'=>$template['id'],'session_id'=>$session['id'],'original_unchanged'=>true],JSON_UNESCAPED_UNICODE)."\n";
