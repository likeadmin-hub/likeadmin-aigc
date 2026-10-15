<?php
// Deterministic preservation probes, not an LLM answer-quality benchmark. No paid transport.
namespace app\common\service\power {
 final class MarketTextModelRuntimeService {
  public static array $calls=[];public static $reply;public static $after=null;public static array $capacity=[];public static string $instructions='';
  public static function resolveAgentModel(...$args):array{return self::$capacity;}
  public static function modelGroups(...$args):array{return [];}
  public static function generate(int $tenant,int $user,array $params):array{
   $body=json_decode($params['messages'][0]['content'][0]['text'],true);self::$calls[]=$body;self::$instructions=$params['system_prompt'];
   if(self::$after)(self::$after)();
   return ['content'=>json_encode((self::$reply)($body),JSON_UNESCAPED_UNICODE),'usage'=>['prompt_tokens'=>0,'completion_tokens'=>0]];
  }
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentLongReadService as L};
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 use app\common\service\power\MarketTextModelRuntimeService as Fake;
 use app\common\service\open_platform\OpenStore;
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function denied(callable $f){try{$f();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){return;}throw new \RuntimeException('Expected rejection');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
 $tenant=1999999919;$user=$tenant;$now=time();
 $dir=public_path().'uploads/long-read-fidelity-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);$path=$dir.'/source.txt';
 $prefix='甲项目120000元，首付30%即36000元；乙项目80000元单列。旧截止日期2027年6月30日。';
 $split='甲项目不得海外投放';$tail='；仅取得甲方书面授权后才可海外投放。最终更正：截止日期2027年7月15日，原6月30日失效。';
 $source=$prefix.str_repeat('文',4000-mb_strlen($prefix)-mb_strlen($split)).$split.$tail;
 file_put_contents($path,$source);
 Db::startTrans();try{
  $session=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'fidelity fixture','model_code'=>'fixture','status'=>'streaming','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $prior='此前要求：附录中的例外也必须逐条保留；只用于内部评审。';
  AigcLlmMessage::create(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'role'=>'user','content'=>$prior,'seq'=>0,'status'=>'done','token_usage_json'=>[],'finish_reason'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $message=AigcLlmMessage::create(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'role'=>'assistant','content'=>'','seq'=>1,'status'=>'streaming','token_usage_json'=>[],'finish_reason'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$file,'uri'=>substr($path,strlen(public_path())),'name'=>'source.txt','mime'=>'text/plain','size'=>filesize($path),'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
  $id=(int)S::table('run')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'message_id'=>$message['id'],'request_id'=>'fidelity-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>'全文核对金额、首付、截止日期和海外投放例外','preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture']]),'attachments'=>S::encode([['file_id'=>$file]]),'status'=>'running','phase'=>'chat','reply'=>'','lease_token'=>'fixture','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
  $get=static fn()=>S::run($tenant,$user,$id);
  $work=static function()use($id,$get){S::table('run')->where('id',$id)->update(['status'=>'running','lease_token'=>bin2hex(random_bytes(12)),'lease_until'=>time()+1500]);L::work($get());return $get();};
  L::begin($get(),$file);
  $oversize=$prefix.str_repeat('背景',1600).'后续请核对投放例外。';
  $longNote=str_repeat('笔记',270).'本段末尾尚有条件句待衔接。';
  Fake::$reply=static fn($body)=>['summary'=>$oversize,'note'=>$longNote];
  Fake::$after=static fn()=>S::table('run')->where('id',$id)->update(['cancel_requested'=>1]);
  $stopped=$work();Fake::$after=null;
  check(str_contains(S::encode(Fake::$calls[0]['conversation']),$prior),'Long reading lost prior user scope');
  check(!str_contains(Fake::$instructions,'3000')&&!str_contains(Fake::$instructions,'500'),'Length target still biases summary omission');
  check($stopped['status']==='canceled'&&L::view($stopped)[0]['offset']===4000,'Cancel lost completed segment');
  check(L::context($stopped)[0]['summary']===$oversize,'Oversize summary silently truncated');
  $first=L::notes($stopped,$file)['items'][0];
  check($first['note']===$longNote&&$first['source_text']===mb_substr($source,0,4000),'Note/source truncated');
  check($first['summary_verified']===false&&!isset($first['summary']),'Note contract/verification changed');
  L::resume($tenant,$user,$id);L::resume($tenant,$user,$id);
  $corrected=$oversize.str_repeat('细节',200).'最终截止日期2027年7月15日，原6月30日失效。';
  $correctedNote=str_repeat('笔记',270).$tail;
  Fake::$reply=static fn($body)=>['summary'=>$corrected,'note'=>$correctedNote];
  $complete=$work();$context=L::context($complete)[0];
  check(count(Fake::$calls)===2&&Fake::$calls[1]['source']['offset']===4000,'Resume repeated a paid segment');
  check(Fake::$calls[1]['previous_summary']===$oversize,'Next prompt truncated persisted summary');
  check(Fake::$calls[0]['previous_source_tail']===''&&str_ends_with(Fake::$calls[1]['previous_source_tail'],$split),'Raw boundary missing');
  check(mb_strlen(Fake::$calls[1]['previous_source_tail'])===1000,'Raw overlap unbounded');
  check(str_contains(Fake::$calls[1]['previous_source_tail'].Fake::$calls[1]['material'],$split.$tail),'Cross-boundary condition incomplete');
  check($context['summary']===$corrected&&$context['status']==='completed'&&$context['summary_verified']===false,'Late correction lost or summary claimed verified');
  $notes=L::notes($complete,$file)['items'];
  check($notes[1]['note']===$correctedNote&&implode('',array_column($notes,'source_text'))===$source,'Source evidence lost or duplicated');
  check(strlen($complete['preferences'])<2000,'Evidence leaked into bounded run preferences');
  $chat=$work();check($chat['phase']==='chat'&&count(Fake::$calls)===2,'Completion repeated model call');
  foreach([['tenant_id'=>2],['user_id'=>2],['session_id'=>$session['id']+9999]] as $foreign)denied(fn()=>L::notes(array_replace($chat,$foreign),$file));
  echo "PASS oversized summary/note, late correction, raw boundary, canceled/resumed no replay, source reconstruction, owner isolation\n";

  // A valid but incomplete summary is never a certificate of completeness. Raw evidence survives it.
  $p=S::json($chat['preferences']);$p['_long_reads'][$file]['status']='pending';$p['_long_reads'][$file]['offset']=4000;
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode($p),'phase'=>'reading']);
  Fake::$reply=static fn($body)=>['summary'=>'甲项目120000元。','note'=>'原文包含投放条件与日期更正。'];
  $omitted=$work();$context=L::context($omitted)[0];$notes=L::notes($omitted,$file)['items'];
  check($context['summary_verified']===false&&str_contains($context['instruction'],'未提及不等于原文没有'),'Incomplete summary treated as verified');
  check(str_contains(end($notes)['source_text'],'书面授权')&&str_contains(end($notes)['source_text'],'2027年7月15日'),'Omitted facts unavailable independently of summary');
  echo "PASS valid incomplete summary retains raw evidence and is explicitly unverified (not semantic accuracy validation)\n";

  // Legacy inline summaries / event notes remain usable; no historical rewrite required.
  $legacy=S::json($omitted['preferences']);$legacy['_long_reads'][$file]=['file_id'=>$file,'name'=>'source.txt','unit'=>'字','offset'=>4000,'text_offset'=>0,'total'=>mb_strlen($source),'status'=>'pending','summary'=>'历史摘要','notes'=>[['note'=>'历史笔记','offset'=>0]],'segments'=>1,'batch_count'=>1];
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode($legacy),'phase'=>'reading']);
  $legacyRun=$work();$last=end(Fake::$calls);check($last['previous_summary']==='历史摘要'&&$last['previous_source_tail']==='','Legacy summary/cursor broken');
  $legacyNotes=L::notes($legacyRun,$file);check($legacyNotes['items'][0]['note']==='历史笔记','Legacy notes unavailable');
  $eventId=S::json($legacyRun['preferences'])['_long_reads'][$file]['summary_event'];
  S::table('event')->where('id',$eventId)->update(['payload'=>S::encode(['summary'=>'旧事件摘要','note'=>'旧事件笔记','offset'=>4000])]);
  check(L::context($get())[0]['summary']==='旧事件摘要','Legacy event summary unreadable');
  $legacy=S::json($get()['preferences']);$legacy['_long_reads'][$file]['status']='pending';$legacy['_long_reads'][$file]['offset']=4000;
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode($legacy),'phase'=>'reading']);
  $beforeCalls=count(Fake::$calls);$beforeJob=L::view($get());Fake::$capacity=['max_input_tokens'=>50];
  $blocked=$work();Fake::$capacity=[];
  check($blocked['status']==='failed'&&L::view($blocked)===$beforeJob&&count(Fake::$calls)===$beforeCalls,'Capacity rejection advanced cursor, charged or became review-only');
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode($legacy)]);$work();$last=end(Fake::$calls);
  check($last['previous_summary']==='旧事件摘要'&&$last['previous_source_tail']==='','Legacy event resume broken');
  Fake::$reply=static fn($body)=>['summary'=>'','note'=>'invalid'];$bad=$work();
  // Set a pending segment explicitly; a completed run otherwise resumes chat without calling transport.
  $p=S::json($bad['preferences']);$p['_long_reads'][$file]['status']='pending';$p['_long_reads'][$file]['offset']=4000;
  S::table('run')->where('id',$id)->update(['preferences'=>S::encode($p),'phase'=>'reading']);$bad=$work();
  check($bad['status']==='needs_review'&&L::view($bad)[0]['offset']===4000,'Invalid response advanced cursor or allowed replay');denied(fn()=>L::resume($tenant,$user,$id));
  check(S::owner('step',$tenant,$user)->where('run_id',$id)->count()===0,'Reading submitted generation');
  OpenStore::owner('file',$tenant,$user)->where('file_id',$file)->delete();check(L::context($get())===[],'Deleted source leaked evidence');denied(fn()=>L::notes($get(),$file));
  echo "PASS legacy inline/event compatibility, invalid response review-only, deleted source isolation; all fixtures rolled back, zero live calls\n";
 }finally{Db::rollback();@unlink($path);@rmdir($dir);}
}
