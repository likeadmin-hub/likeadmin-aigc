<?php
namespace app\common\service\power {
 final class MarketTextModelRuntimeService {
  public static array $calls=[];public static $after=null;
  public static function modelGroups(...$args):array{return [];}
  public static function generate(int $t,int $u,array $p):array{
   self::$calls[]=json_decode($p['messages'][0]['content'][0]['text'],true);if(self::$after)(self::$after)();
   return ['content'=>json_encode(['summary'=>'摘要仅用于导航，需核对原文。','note'=>'本段已保存，来源见原文。'],JSON_UNESCAPED_UNICODE),'usage'=>['prompt_tokens'=>0,'completion_tokens'=>0]];
  }
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentContextService as C,AgentSourceVerifier as V,AgentLongReadService as L};
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 use app\common\service\open_platform\OpenStore;
 use app\common\service\power\MarketTextModelRuntimeService as Fake;
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function denied(callable $f){try{$f();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){return;}throw new \RuntimeException('Expected rejection');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
 $t=1999999921;$u=$t;$now=time();
 $tempDir=public_path().'uploads/source-evidence-'.bin2hex(random_bytes(8));mkdir($tempDir,0700,true);
 $make=static function(string $source)use($t,$u,$now){
  $s=AigcLlmSession::create(['tenant_id'=>$t,'user_id'=>$u,'title'=>'source fixture','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $base=['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'token_usage_json'=>[],'finish_reason'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>0];
  $m=AigcLlmMessage::create($base+['role'=>'user','content'=>$source,'seq'=>1,'status'=>'done','parent_user_message_id'=>0]);
  $a=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'','seq'=>2,'status'=>'streaming','parent_user_message_id'=>$m['id']]);
  $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert(['tenant_id'=>$t,'user_id'=>$u,'file_id'=>$file,'uri'=>'uploads/evidence-fixture.docx','name'=>'资料.docx','mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
  $id=(int)S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'message_id'=>$a['id'],'request_id'=>'source-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('c',64),'content'=>'按原文核对关键条件。','preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'fixture']]),'attachments'=>S::encode([['file_id'=>$file]]),'status'=>'running','phase'=>'chat','reply'=>'','lease_token'=>'fixture','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
  $artifact=(int)S::table('artifact')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'run_id'=>$id,'step_id'=>0,'identity_hash'=>hash('sha256',$file),'title'=>'甲项目原文','kind'=>'document','data'=>S::encode(['file_id'=>$file,'content'=>$source]),'create_time'=>$now]);
  return [S::run($t,$u,$id),$file,(int)$m['id'],$artifact];
 };
 Db::startTrans();try{
  $quote='甲项目截止日期2027年7月15日';$source=str_repeat('前',3995).$quote.'；原6月30日失效。不得海外投放，除非有书面授权。';
  [$run,$file,$message,$artifact]=$make($source);
  $verified=V::verify($run,['file_id'=>$file,'quote'=>$quote,'offset'=>0]);
  check($verified['matched']&&$verified['source']['character_offset']===3995&&str_contains($verified['context'],$quote),'Quote crossing 4000 boundary lost');
  check($verified['semantic_verified']===false&&$verified['source']['kind']==='asset','Text match promoted to semantic proof');
  $context=V::verify($run,['file_id'=>$file,'quote'=>$quote,'offset'=>3900]);
  check(str_contains($context['context'],'原6月30日失效')&&str_contains($context['context'],'除非有书面授权'),'Local exception/correction context missing');
  $missing=V::verify($run,['file_id'=>$file,'quote'=>'甲项目截止日期2027年8月15日']);
  check(!$missing['matched']&&$missing['source']['character_offset']===null&&str_contains($missing['instruction'],'不代表全文没有'),'Mismatch overclaimed absence');
  $fromMessage=V::verify($run,['message_id'=>$message,'quote'=>$quote,'offset'=>3900]);
  check($fromMessage['matched']&&$fromMessage['source']['role']==='user','Message source unsupported');
  foreach([['file_id'=>$file,'message_id'=>$message,'quote'=>$quote],['quote'=>$quote],['file_id'=>$file,'quote'=>' '],['file_id'=>$file,'quote'=>str_repeat('字',1001)]] as $bad)denied(fn()=>V::verify($run,$bad));
  foreach([['tenant_id'=>$t+1],['user_id'=>$u+1]] as $foreign){denied(fn()=>V::verify(array_replace($run,$foreign),['file_id'=>$file,'quote'=>$quote]));denied(fn()=>V::verify(array_replace($run,$foreign),['message_id'=>$message,'quote'=>$quote]));}
  [$other]=$make('其他会话');denied(fn()=>V::verify($other,['file_id'=>$file,'quote'=>$quote]));denied(fn()=>V::verify($other,['message_id'=>$message,'quote'=>$quote]));
  $changed='甲项目截止日期2027年8月15日；须另行核对。';
  S::table('artifact')->where('id',$artifact)->update(['data'=>S::encode(['file_id'=>$file,'content'=>$changed])]);
  $new=V::verify($run,['file_id'=>$file,'quote'=>$quote]);check(!$new['matched']&&$new['source']['window_sha256']!==$verified['source']['window_sha256'],'Edited source reused stale match');
  AigcLlmMessage::where('id',$message)->update(['content'=>str_repeat('须书面授权。',2000)]);
  $repeated=V::verify($run,['message_id'=>$message,'quote'=>'须书面授权']);check($repeated['occurrences_in_window']===2&&$repeated['count_is_lower_bound']&&!$repeated['semantic_verified'],'Repeated quote ambiguity missing');
  AigcLlmMessage::where('id',$message)->update(['delete_time'=>$now]);denied(fn()=>V::verify($run,['message_id'=>$message,'quote'=>'须书面授权']));
  OpenStore::owner('file',$t,$u)->where('file_id',$file)->delete();denied(fn()=>V::verify($run,['file_id'=>$file,'quote'=>$quote]));
  check(Fake::$calls===[]&&S::run($t,$u,$run['id'])['preferences']===$run['preferences'],'Verification invoked model or mutated run');
  echo "PASS source checks: exact/mismatch/cross-boundary, context, ambiguity, live edits/deletion, owners/sessions, zero inference or approval\n";

  // Actual local PDF extraction: page ranges are not character offsets; no vision/model call.
  $stream='BT /F1 18 Tf 60 720 Td (Deadline July 15. Written consent required.) Tj ET';
  $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',"<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream"];
  $pdf="%PDF-1.4\n";$offsets=[];foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach($offsets as $o)$pdf.=sprintf('%010d 00000 n ',$o)."\n";$pdf.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
  $pdfPath=$tempDir.'/source.pdf';file_put_contents($pdfPath,$pdf);
  [$pdfRun,$pdfFile,$unused,$pdfArtifact]=$make('PDF source');S::table('artifact')->where('id',$pdfArtifact)->delete();
  OpenStore::owner('file',$t,$u)->where('file_id',$pdfFile)->update(['name'=>'source.pdf','mime'=>'application/pdf','uri'=>substr($pdfPath,strlen(public_path())),'size'=>strlen($pdf)]);
  $proof=V::verify($pdfRun,['file_id'=>$pdfFile,'quote'=>'Deadline July 15.','offset'=>0]);
  check($proof['matched']&&$proof['source']['unit']==='页'&&$proof['source']['page_start']===1&&$proof['source']['page_end']===1&&$proof['source']['character_offset']===null,'PDF range treated as character offset');
  check(!str_contains(S::encode($proof),'data:image')&&!$proof['semantic_verified'],'Verifier leaked visuals or claimed semantic certainty');
  OpenStore::owner('file',$t,$u)->where('file_id',$pdfFile)->update(['name'=>'video.mp4','mime'=>'video/mp4']);
  denied(fn()=>V::verify($pdfRun,['file_id'=>$pdfFile,'quote'=>'本批视频画面']));
  check(Fake::$calls===[],'Source/PDF verification called a model');
  echo "PASS actual PDF text/page range and unsupported media guard, zero model calls\n";

  // Structure-aware jobs advance by actual consumed text; old jobs keep the old fixed cursor.
  $structured=str_repeat('甲',3500)."\n\n".str_repeat('乙',1800).'。更正：交付日期7月15日。';
  foreach([false,true] as $legacy){
   [$r,$f]=$make($structured);L::begin($r,$f);$get=static fn()=>S::run($t,$u,$r['id']);
   if($legacy){$p=S::json($get()['preferences']);unset($p['_long_reads'][$f]['segmentation']);S::table('run')->where('id',$r['id'])->update(['preferences'=>S::encode($p)]);}
   $work=static function()use($r,$get){S::table('run')->where('id',$r['id'])->update(['status'=>'running','lease_token'=>bin2hex(random_bytes(8)),'lease_until'=>time()+1500]);L::work($get());return $get();};
   Fake::$calls=[];Fake::$after=static fn()=>S::table('run')->where('id',$r['id'])->update(['cancel_requested'=>1]);$stopped=$work();Fake::$after=null;
   $end=$legacy?4000:3502;check($stopped['status']==='canceled'&&L::view($stopped)[0]['offset']===$end,'Structured/legacy cursor or cancellation wrong');
   L::resume($t,$u,$r['id']);L::resume($t,$u,$r['id']);$done=$work();
   check(L::view($done)[0]['status']==='completed'&&count(Fake::$calls)===2,'Resume repeated source or skipped completion');
   check(implode('',array_column(Fake::$calls,'material'))===$structured,'Structured source lost/duplicated');
   check(Fake::$calls[1]['source']['offset']===$end,'Resume jumped cursor');
   check($legacy?!isset(Fake::$calls[0]['source']['boundary']):Fake::$calls[0]['source']['boundary']==='paragraph','Boundary metadata wrong');
   $notes=L::notes($done,$f);check(implode('',array_column($notes['items'],'source_text'))===$structured,'Stored evidence differs from model material');
   $calls=count(Fake::$calls);$chat=$work();check($chat['phase']==='chat'&&count(Fake::$calls)===$calls,'Final resume repeated a model call');
  }
  echo "PASS new structural and legacy fixed jobs, cancel/resume, exact stored source and no completed-segment replay; all fixtures rolled back\n";
 }finally{Db::rollback();foreach(glob($tempDir.'/*')?:[] as $path)@unlink($path);@rmdir($tempDir);}
}
