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
$passed=0;
function assertAgent($ok,$message){global $passed;$passed++;if(!$ok)throw new RuntimeException($message);}
config(['enabled'=>true],'decoration_agent');request()->tenantId=1;
$baseline=Db::name('decorate_page')->where('tenant_id',1)->where('id',1)->find();
$baselineJson=json_encode($baseline);
$tools=new ReflectionMethod(S::class,'queryTool');$tools->setAccessible(true);$icons=$tools->invoke(null,1,1,[],[],[],['type'=>'icons'],[],[]);assertAgent($icons[0]['options'][0]['value']==='⌕'&&$icons[1]['options'][0]['value']==='auto','icon tool uses mobile renderer values, not desktop icon class names');
$index=new ReflectionMethod(S::class,'componentIndex');$index->setAccessible(true);$legacy=$index->invoke(null,[['name'=>'news','content'=>[]]]);assertAgent($legacy[0]['id']==='legacy_news_0'&&$legacy[0]['path']===[0],'context exposes legacy component IDs without stored rewrites');
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
assertAgent(array_key_exists('resolved_sources',S::candidate(1,1,(int)$cid)),'candidate resolves its own sources without storing results');
$messageId=Db::name('decorate_agent_message')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>0,'role'=>'user','content'=>'参考图片','attachments'=>S::json([['file_id'=>1,'uses'=>['style']]]),'create_time'=>time(),'update_time'=>time()]);
$history=S::session(1,1,$session['id']);assertAgent($history['messages'][0]['attachments'][0]['uses']===['style'],'history attachments decoded');
assertAgent(S::hash(S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$page['id']])))===S::hash($initial),'candidate did not mutate draft');
$applied=S::apply(1,1,['candidate_id'=>$cid,'idempotency_key'=>'acceptance:'.$cid]);
assertAgent(array_key_exists('resolved_sources',$applied['page']),'applied page returns fresh sources');
assertAgent(S::payload($applied['page'])['data'][0]['styles']['margin_top']===30,'apply changes draft');
assertAgent((int)S::session(1,1,$session['id'])['checkpoints'][0]['candidate_id']===(int)$cid,'persistent checkpoint identifies applied candidate');
$replayed=S::apply(1,1,['candidate_id'=>$cid,'idempotency_key'=>'acceptance:'.$cid]);assertAgent($replayed['checkpoint_id']===$applied['checkpoint_id'],'apply idempotent');
$rolled=S::rollback(1,1,['checkpoint_id'=>$applied['checkpoint_id'],'idempotency_key'=>'undo:'.$cid]);assertAgent(S::hash(S::payload($rolled['page']))===S::hash($initial),'rollback restores');
$again=S::rollback(1,1,['checkpoint_id'=>$applied['checkpoint_id'],'idempotency_key'=>'undo:'.$cid]);assertAgent($again['checkpoint_id']===$rolled['checkpoint_id'],'rollback idempotent');
$new=$candidate;$new['new_page']=true;$new['name']='Agent新页面';$nid=$insertCandidate($new,$initial);
$created=S::apply(1,1,['candidate_id'=>$nid,'idempotency_key'=>'new:'.$nid]);assertAgent($created['page']['id']!==$page['id'],'new page created');
$undone=S::rollback(1,1,['checkpoint_id'=>$created['checkpoint_id'],'idempotency_key'=>'undo_new:'.$nid]);assertAgent($undone['deleted']&&$undone['page']['id']===$page['id'],'new page rollback restores origin');
assertAgent(S::session(1,1,$session['id'])['page_id']===$page['id'],'new page session origin restored');$replayNew=S::rollback(1,1,['checkpoint_id'=>$created['checkpoint_id'],'idempotency_key'=>'undo_new:'.$nid]);assertAgent($replayNew['deleted_page_id']===$created['page']['id'],'new page rollback replay preserves deleted id');
try{S::rollback(1,1,['checkpoint_id'=>$undone['checkpoint_id'],'idempotency_key'=>'unsafe_undo_record:'.$nid]);throw new RuntimeException('unsafe undo_create reversed');}catch(RuntimeException $e){assertAgent(str_contains($e->getMessage(),'不能直接恢复'),'undo_create never overwrites origin page');}
$redo=S::apply(1,1,['candidate_id'=>$nid,'idempotency_key'=>'redo_new:'.$nid]);assertAgent($redo['page']['id']!==$created['page']['id'],'new page redo recreates');
$imageBase=S::payload($redo['page']);$imageCandidate=O::apply($imageBase,[['type'=>'add_component','name'=>'banner']],['scope'=>'page'])['candidate'];$imageId=$insertCandidate($imageCandidate,$imageBase);$imageApplied=S::apply(1,1,['candidate_id'=>$imageId,'idempotency_key'=>'image_apply:'.$imageId]);
$banner=end($imageCandidate['data']);$imageRequest=O::apply($imageCandidate,[['type'=>'request_image','widget_id'=>$banner['id'],'field'=>'content.data.0.image','prompt'=>'仅数据库回填测试']],['scope'=>'page'])['image_requests'][0];
$slotId=Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session['id'],'run_id'=>0,'candidate_id'=>$imageId,'slot_key'=>$banner['id'].':content.data.0.image','status'=>'running','request_json'=>S::json($imageRequest),'result_json'=>'{}','create_time'=>time(),'update_time'=>time()]);$slot=Db::name('decorate_agent_image_slot')->where('id',$slotId)->find();
$hydrate=new ReflectionMethod(S::class,'hydrate');$hydrate->setAccessible(true);$hydrate->invoke(null,$slot,'https://fixture.invalid/generated-image.png');
$imageUndo=S::rollback(1,1,['checkpoint_id'=>$imageApplied['checkpoint_id'],'idempotency_key'=>'image_undo:'.$imageId]);assertAgent(S::hash(S::payload($imageUndo['page']))===S::hash($imageBase),'whole AI apply rollback includes later image completion');
$hydrate->invoke(null,$slot,'https://fixture.invalid/late-image.png');assertAgent(S::hash(S::payload(T::pageDetail(1,['template_id'=>$template['id'],'id'=>$imageUndo['page']['id']])))===S::hash($imageBase),'late image cannot resurrect undone apply');
assertAgent(json_encode(Db::name('decorate_page')->where('tenant_id',1)->where('id',1)->find())===$baselineJson,'existing page untouched');
echo json_encode(['passed'=>$passed,'template_id'=>$template['id'],'session_id'=>$session['id'],'original_unchanged'=>true],JSON_UNESCAPED_UNICODE)."\n";
