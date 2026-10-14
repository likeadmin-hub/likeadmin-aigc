<?php
namespace app\common\service\power {
 final class MarketTextModelRuntimeService {
  public static array $calls=[];public static $after=null;public static bool $fail=false;
  public static function resolveAgentModel(int $t,string $model,bool $vision=false):array{return [];}
  public static function modelGroups(int $tenant):array{return [];}
  public static function generate(int $t,int $u,array $params):array{
   self::$calls[]=$params;if(self::$fail)throw new \RuntimeException('uncertain response');
   if(self::$after)(self::$after)();
   $body=json_decode($params['messages'][0]['content'][0]['text'],true);
   if(mb_strlen($body['material'])>8000||mb_strlen($body['previous_summary'])>3000)throw new \RuntimeException('Unbounded context');
   return ['content'=>json_encode(['summary'=>'累计读取到 '.$body['source']['end'],'note'=>'本段 '.$body['source']['offset'].' 的事实']),'consumption_id'=>count(self::$calls),'usage'=>['prompt_tokens'=>10,'completion_tokens'=>10]];
  }
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentLongReadService as L,AgentExecutionService as E};
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 use app\common\service\power\MarketTextModelRuntimeService as Fake;
 use app\common\service\open_platform\OpenStore;
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function denied(callable $f){try{$f();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){return;}throw new \RuntimeException('Expected rejection');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
 $dir=public_path().'uploads/long-read-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);$path=$dir.'/source.txt';file_put_contents($path,str_repeat('资料正文。',4000));
 Db::startTrans();try{
  $now=time();$session=AigcLlmSession::create(['tenant_id'=>1,'user_id'=>1,'title'=>'long read','model_code'=>'fixture','status'=>'streaming','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $message=AigcLlmMessage::create(['tenant_id'=>1,'user_id'=>1,'session_id'=>$session['id'],'role'=>'assistant','content'=>'正在读取','seq'=>1,'status'=>'streaming','token_usage_json'=>[],'finish_reason'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert(['tenant_id'=>1,'user_id'=>1,'file_id'=>$file,'uri'=>substr($path,strlen(public_path())),'name'=>'长资料.txt','mime'=>'text/plain','size'=>filesize($path),'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
  $id=(int)S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>$session['id'],'message_id'=>$message['id'],'request_id'=>'read-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>'总结全文','preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture']]),'attachments'=>S::encode([['file_id'=>$file]]),'status'=>'running','phase'=>'chat','reply'=>'正在读取','lease_token'=>'initial','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
  $run=S::run(1,1,$id);L::begin($run,$file);L::begin($run,$file);check(count(L::view(S::run(1,1,$id)))===1&&Fake::$calls===[],'Scheduling duplicated work or charged');
  check(L::queue($run),'Did not hand off to reading worker');
  $work=static function()use($id){S::table('run')->where('id',$id)->update(['status'=>'running','lease_token'=>bin2hex(random_bytes(12)),'lease_until'=>time()+1500]);L::work(S::run(1,1,$id));return S::run(1,1,$id);};
  $first=$work();check(L::view($first)[0]['offset']===4000,'First chunk not persisted');
  // A stop arriving during a paid response saves that completed chunk, then stops scheduling.
  Fake::$after=static fn()=>S::table('run')->where('id',$id)->update(['cancel_requested'=>1]);$stopped=$work();Fake::$after=null;
  check($stopped['status']==='canceled'&&L::view($stopped)[0]['offset']===8000,'Stop lost a paid chunk');
  L::resume(1,1,$id);L::resume(1,1,$id);$next=$work();check(L::view($next)[0]['offset']===12000&&count(Fake::$calls)===3,'Resume reread or recharged completed chunks');
  $work();$complete=$work();check(L::view($complete)[0]['status']==='completed'&&L::view($complete)[0]['offset']===20000,'Long document was truncated');
  $resumed=$work();check($resumed['phase']==='chat'&&count(Fake::$calls)===5,'Completion did not resume original chat exactly once');
  $notes=L::notes($resumed,$file);check(count($notes['items'])===5&&$notes['items'][4]['offset']===16000&&$notes['next_offset']===null,'Source citations lost');
  check(count(L::context($resumed))===1,'Summary unavailable to original request');
  foreach([['tenant_id'=>2],['user_id'=>2],['session_id'=>$session['id']+9999]] as $foreign)denied(fn()=>L::notes(array_replace($resumed,$foreign),$file));
  $saved=OpenStore::owner('file',1,1)->where('file_id',$file)->find();OpenStore::owner('file',1,1)->where('file_id',$file)->delete();
  check(L::context($resumed)===[],'Deleted source survived via summary');denied(fn()=>L::notes($resumed,$file));OpenStore::table('file')->insert($saved);
  $p=S::json($resumed['preferences']);$p['_long_reads'][$file]['status']='pending';$p['_long_reads'][$file]['offset']=16000;
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode($p),'phase'=>'reading','status'=>'queued']);Fake::$fail=true;$uncertain=$work();
  check($uncertain['status']==='needs_review'&&L::view($uncertain)[0]['offset']===16000,'Uncertain model response advanced cursor');denied(fn()=>L::resume(1,1,$id));
  check(S::owner('step',1,1)->where('run_id',$id)->count()===0,'Reading submitted generation');
  echo "Long reading passed: 20000 characters / 5 bounded calls, durable citations, stop/resume without replay, idempotent scheduling, owner/deletion isolation, uncertain response blocked; mocked transport, rolled back\n";
 }finally{Db::rollback();@unlink($path);@rmdir($dir);}
}
