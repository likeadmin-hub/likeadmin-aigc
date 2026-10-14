<?php
// Real local persistence, mocked topic decisions, no generation or charges; rolled back.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentTopicService as T,AgentContextService as C};
use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
use app\common\service\open_platform\OpenStore;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function denied(callable $f){try{$f();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected topic rejection');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
// Enable counting only in this CLI process, before opening the rollback transaction.
$config=Db::getConfig();$config['connections']['mysql']['trigger_sql']=true;Db::setConfig($config);Db::connect('mysql',true);
Db::startTrans();try{
 $tenant=1999999903;$user=1999999903;$now=time();
 $session=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'topics','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
 $make=function($text,$files=[])use($tenant,$user,$session,$now){
  $base=['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'status'=>'done','token_usage_json'=>[],'finish_reason'=>''];
  $m=AigcLlmMessage::create($base+['role'=>'user','content'=>$text,'seq'=>1,'parent_user_message_id'=>0]);
  $a=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'历史助手正文不能进入话题路由','seq'=>2,'parent_user_message_id'=>$m['id']]);
  $id=S::table('run')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'message_id'=>$a['id'],'request_id'=>'topic-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('a',64),'content'=>$text,'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture']]),'attachments'=>S::encode($files),'status'=>'success','phase'=>'chat','reply'=>'','create_time'=>$now,'update_time'=>$now]);
  return S::run($tenant,$user,(int)$id);
 };
 $first=T::resolve($make('生成轻柔钢琴音乐'),static function(){throw new RuntimeException('First request should not call a classifier');});
 $file='of_'.bin2hex(random_bytes(16));
 OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$file,'uri'=>'uploads/topic-fixture.png','name'=>'音乐素材.png','mime'=>'image/png','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
 S::table('artifact')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'run_id'=>$first['id'],'step_id'=>0,'identity_hash'=>hash('sha256',$file),'title'=>'钢琴配图','kind'=>'image','data'=>S::encode(['file_id'=>$file]),'create_time'=>$now]);
 S::table('step')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'run_id'=>$first['id'],'step_key'=>hash('sha256',$file),'title'=>'音乐任务','tool_code'=>'aigc_music','prepared'=>S::encode(['params'=>['prompt'=>'钢琴'],'quote'=>['user'=>65]]),'result'=>'','status'=>'running','task_id'=>123,'create_time'=>$now,'update_time'=>$now]);
 C::created($first,$file);
 $new=T::resolve($make('帮我写会议通知'),function($candidates)use($file){
  $json=S::encode($candidates);check(!str_contains($json,$file)&&!str_contains($json,'历史助手')&&!str_contains($json,'65'),'Router received media, assistant text or task state');
  return ['action'=>'new'];
 });
 $context=C::build($new);
 check(count($context['recent'])===1&&$context['recent'][0]['content']==='帮我写会议通知','Independent topic inherited messages');
 check($context['summary']['requests']===[]&&$context['focus_assets']===[]&&$context['artifacts']===[],'Independent topic inherited summary/materials');
 check(count($context['tasks'])===1&&$context['tasks'][0]['steps']===[],'Independent topic inherited music task');
 check(C::searchHistory($new,'钢琴')['items']===[]&&C::findArtifacts($new)['items']===[],'Retrieval escaped topic');denied(fn()=>C::readAsset($new,$file));
 // Finishing an old background task cannot replace a newer topic's focus.
 C::created($first,$file);check(C::build($new)['focus_assets']===[],'Old completion crossed topic');
 $resume=T::resolve($make('继续刚才的钢琴音乐，加快节奏'),fn()=>['action'=>'continue','topic_id'=>(int)$first['id']]);
 $context=C::build($resume);
 check($context['focus']['file_ids']===[$file]&&count($context['artifacts'])===1,'Continuation lost related material');
 check(!str_contains(S::encode($context),'会议通知')&&str_contains(S::encode($context),'音乐任务'),'Continuation imported unrelated topic or lost task');
 check(C::readAsset($resume,$file)['file_id']===$file,'Related asset inaccessible');
 $third=T::resolve($make('把节奏再放慢一点'),fn()=>['action'=>'continue','topic_id'=>(int)$first['id']]);
 check(count(T::runIds($third))===3,'Continuation did not preserve topic chain');
 $queries=0;Db::listen(static function()use(&$queries){$queries++;});
 $before=$queries;for($i=0;$i<10;$i++)$uncached=T::runIds($third);$uncachedQueries=$queries-$before;
 $before=$queries;\app\common\service\app\aigc_llm\agent\AgentReadCache::scope(function()use($third,$uncached){
  for($i=0;$i<10;$i++)check(T::runIds($third)===$uncached,'Cached topic membership changed');
  foreach([['tenant_id'=>$third['tenant_id']+1],['user_id'=>$third['user_id']+1],['session_id'=>$third['session_id']+9999]] as $foreign)denied(fn()=>T::runIds(array_replace($third,$foreign)));
 });$cachedQueries=$queries-$before;
 check($cachedQueries<$uncachedQueries,'Repeated topic queries not reduced');
 echo "Topic membership SQL: $uncachedQueries uncached -> $cachedQueries scoped (including 3 rejected owner checks)\n";
 $reset=T::resolve($make('重新开始，写一首诗'),static function(){throw new RuntimeException('Explicit reset invoked routing');});
 check(T::state($reset)['decision']==='new'&&count(C::build($reset)['recent'])===1&&C::build($reset)['focus_assets']===[],'Reset inherited old topic');
 $unclear=T::resolve($make('帮我改一下'),fn()=>['action'=>'clarify']);denied(fn()=>T::assertResolved($unclear));check(count(C::build($unclear)['recent'])===1,'Ambiguous reference loaded a guessed topic');
 $invalid=T::resolve($make('继续'),fn()=>['action'=>'continue','topic_id'=>999999999]);check(T::state($invalid)['decision']==='clarify','Invalid/foreign topic accepted');
 $again=T::resolve($resume,static function(){throw new RuntimeException('Frozen decision classified twice');});check(T::state($again)===T::state($resume),'Decision changed on replay');
 $explicit=T::resolve($make('分析这张上传的图',[['file_id'=>$file]]),fn()=>['action'=>'new']);
 $context=C::build($explicit);check($context['focus']['file_ids']===[$file]&&count($context['tasks'])===1,'Explicit attachment imported original task');
 check(S::table('step')->where('run_id',$first['id'])->value('status')==='running','Topic switch changed old task status');
 // Implicit continuations need the pending question, not just the user requests.
 $video=T::resolve($make('重新开始，帮我生成一个15s的视频'),fn()=>['action'=>'new']);
 S::update($video,['reply'=>'可以，请上传首帧参考图，我会帮你写视频提示词。']);
 $continue=function($text,$files=[],$expectedReply='')use($make,$video){
  return T::resolve($make($text,$files),function($topics)use($video,$expectedReply){
   check($topics[0]['is_latest']===true&&$topics[0]['topic_id']===$video['id'],'Latest dialogue cue not associated with its root');
   check(count(array_filter($topics,fn($t)=>$t['is_latest']))===1,'Multiple latest topics');
   check(str_contains($topics[0]['last_reply'],$expectedReply),'Missing last visible response for implicit continuation');
   return ['action'=>'continue','topic_id'=>(int)$video['id']];
  });
 };
 $help=$continue('但是我不会写提示词',[],'首帧参考图');
 S::update($help,['reply'=>'我会帮你写。请先上传参考图。']);
 $reference=$continue('这是参考图',[['file_id'=>$file]],'请先上传参考图');
 C::build($reference); // Persist uploaded reference as this topic's focus.
 S::update($reference,['reply'=>'这是黄色电视机角色，可以保持主体，为15秒视频补充动作。']);
 $style=$continue('你给我写一个可爱画风的提示词吧',[],'黄色电视机角色');
 $context=C::build($style);$body=S::encode($context);
 check(count(T::runIds($style))===4&&str_contains($body,'15s')&&$context['focus']['file_ids']===[$file],'Implicit continuation lost original goal/reference');
 check(!str_contains($body,'会议通知')&&!str_contains($body,'音乐任务'),'Continuation imported another topic');
 S::update($style,['reply'=>str_repeat('长',1800).'请确认镜头和时长']);
 T::resolve($make('另做一张小猫海报'),function($topics){
  check(mb_strlen($topics[0]['last_reply'])<=1203&&str_ends_with($topics[0]['last_reply'],'请确认镜头和时长'),'Reply cue is not bounded or lost final question');
  return ['action'=>'new'];
 });
 echo "Home Agent topics passed: new, resume, chains, reset, ambiguity, scoped retrieval, late completion, explicit references and idempotent routing; rolled back\n";
}finally{Db::rollback();}
