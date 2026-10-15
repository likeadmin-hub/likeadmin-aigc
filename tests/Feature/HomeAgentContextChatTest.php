<?php
namespace app\common\service\power {
 // Block all external text requests before autoloading the real execution service.
 final class MarketTextModelRuntimeService {
  public static array $requests=[];public static string $file='';
  public static function resolveAgentModel(int $tenant,string $model,bool $vision=false):array{return [];}
  public static function resolveModel(int $tenant,$model,bool $vision=false):array{throw new \InvalidArgumentException('No enhancement model in offline fixture');}
  public static function modelGroups(int $tenant):array{return [];}
  public static function generate(int $tenant,int $user,array $params,?callable $onEvent=null):array{
   self::$requests[]=$params;
   $n=count(self::$requests);
   if($n===1)return ['tool_calls'=>[['id'=>'recall-requirements','function'=>['name'=>'read_session_context','arguments'=>json_encode(['payload'=>['action'=>'requirements','query'=>'负责人']])]]]];
   if($n===2)return ['tool_calls'=>[['id'=>'read-document','function'=>['name'=>'read_session_context','arguments'=>json_encode(['payload'=>['action'=>'read_asset','file_id'=>self::$file]])]]]];
   if($n===3)return ['tool_calls'=>[['id'=>'verify-quote','function'=>['name'=>'read_session_context','arguments'=>json_encode(['payload'=>['action'=>'verify_source','file_id'=>self::$file,'quote'=>'09:10 产品介绍','offset'=>0]])]]]];
   if($n===4)return ['tool_calls'=>[['id'=>'focus-document','function'=>['name'=>'set_creation_focus','arguments'=>json_encode(['payload'=>['file_id'=>self::$file,'goal'=>'完善发布会流程']])]]]];
   if($n===5)return ['content'=>'流程表包含开场、产品介绍和体验环节，可以为各环节补充负责人。'];
   throw new \RuntimeException('Unexpected model retry');
  }
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
 use think\facade\Db;
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentContextService as C,AgentExecutionService as E};
 use app\common\service\power\MarketTextModelRuntimeService as Fake;
 use app\common\service\open_platform\OpenStore;
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 Db::startTrans();try{
  $t=1999999903;$u=$t;$now=time();
  $s=AigcLlmSession::create(['tenant_id'=>$t,'user_id'=>$u,'title'=>'chat context fixture','model_code'=>'fixture','status'=>'streaming','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $base=['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'token_usage_json'=>[],'finish_reason'=>''];
  $oldUser=AigcLlmMessage::create($base+['role'=>'user','content'=>'流程表要求：所有环节必须保留负责人。','seq'=>1,'status'=>'done','parent_user_message_id'=>0]);
  // A large preceding answer moves the old user requirement outside the recent-character window.
  $oldAnswer=AigcLlmMessage::create($base+['role'=>'assistant','content'=>str_repeat('背景。',5000),'seq'=>2,'status'=>'done','parent_user_message_id'=>$oldUser['id']]);
  $oldId=(int)S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'message_id'=>$oldAnswer['id'],'request_id'=>'context-old-'.bin2hex(random_bytes(8)),'payload_hash'=>str_repeat('b',64),'content'=>$oldUser['content'],'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'chosen-model']]),'attachments'=>'[]','status'=>'success','phase'=>'chat','reply'=>'','create_time'=>$now,'update_time'=>$now]);
  $m=AigcLlmMessage::create($base+['role'=>'user','content'=>'看看刚才的流程表，再补充负责人。','seq'=>1,'status'=>'done','parent_user_message_id'=>0]);
  $a=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'','seq'=>2,'status'=>'streaming','parent_user_message_id'=>$m['id']]);
  $id=S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'message_id'=>$a['id'],'request_id'=>'context-chat-'.bin2hex(random_bytes(8)),'payload_hash'=>str_repeat('a',64),'content'=>$m['content'],'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'chosen-model']]),'attachments'=>'[]','status'=>'running','phase'=>'chat','reply'=>'','lease_token'=>'fixture','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'chosen-model'],'_topic'=>['root_run_id'=>$oldId,'decision'=>'continue']])]);
  Fake::$file='of_'.bin2hex(random_bytes(16));
  OpenStore::table('file')->insert(['tenant_id'=>$t,'user_id'=>$u,'file_id'=>Fake::$file,'uri'=>'uploads/context-chat.docx','name'=>'流程表.docx','mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
  S::table('artifact')->insert(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'run_id'=>$id,'step_id'=>0,'identity_hash'=>hash('sha256',Fake::$file),'title'=>'发布会流程表','kind'=>'document','data'=>S::encode(['file_id'=>Fake::$file,'content'=>'09:00 开场；09:10 产品介绍；09:30 体验。']),'create_time'=>$now]);
  $run=S::run($t,$u,(int)$id);$chat=new \ReflectionMethod(E::class,'chat');$chat->setAccessible(true);$chat->invoke(null,$run);
  check(count(Fake::$requests)===5,'Context tools not executed in real chat loop');
  check(str_contains(Fake::$requests[0]['system_prompt'],'关键需求原文线索')&&str_contains(Fake::$requests[0]['system_prompt'],'所有环节必须保留负责人'),'Old requirement not injected into actual model input');
  check(str_contains(S::encode(Fake::$requests[1]['messages']),'keyword_candidates')||str_contains(S::encode(Fake::$requests[1]['messages']),'literal_search'),'Requirements tool not executed');
  $second=Fake::$requests[2];$body=S::encode($second['messages']);
  check(str_contains($body,'09:10 产品介绍'),'Persisted document never reached the model');
  $checked=S::encode(Fake::$requests[3]['messages']);check(str_contains($checked,'exact_quote')&&str_contains($checked,'semantic_verified'),'Source verification did not reach actual model input');
  check(str_contains($second['system_prompt'],'真实任务记录')&&str_contains($second['system_prompt'],'长对话摘记'),'Context sections not injected');
  check($second['model_selection']==='chosen-model'&&$second['_disable_model_fallback'],'Model preference changed');
  check(C::build(S::run($t,$u,(int)$id))['focus']['file_ids']===[Fake::$file],'Selected document not persisted');
  $view=E::detail($t,$u,(int)$id);check($view['status']==='success'&&$view['steps']===[],'Read tools submitted or approved generation');
  check(S::table('event')->where('run_id',$id)->where('event_type','context.prepared')->count()===1,'Context diagnostics missing');
  echo "Home Agent context chat passed: actual Neuron execution, document read, persistent focus and unchanged model; transport mocked, rolled back\n";
 }finally{Db::rollback();}
}
