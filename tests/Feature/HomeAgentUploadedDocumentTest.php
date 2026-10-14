<?php
namespace app\common\service\power {
 final class MarketTextModelRuntimeService {
  public static array $requests=[]; public static string $file='';
  public static function resolveAgentModel(int $t,string $m,bool $v=false):array{return [];}
  public static function resolveModel(int $t,$m,bool $v=false):array{throw new \InvalidArgumentException('Offline fixture');}
  public static function modelGroups(int $t):array{return [];}
  public static function generate(int $t,int $u,array $params,?callable $onEvent=null):array{
   self::$requests[]=$params;$n=count(self::$requests);
   if($n<=2)return ['tool_calls'=>[['id'=>'read-'.$n,'function'=>['name'=>'read_session_context','arguments'=>json_encode(['payload'=>['action'=>'read_asset','file_id'=>self::$file,'offset'=>($n-1)*4000]])]]]];
   if($n===3)return ['content'=>'已读取流程表：先开场，最后安排观众体验。'];
   throw new \RuntimeException('Unexpected retry');
  }
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentDocumentReader as D,AgentStore as S,AgentContextService as C,AgentExecutionService as E};
 use app\common\service\power\MarketTextModelRuntimeService as Fake;
 use app\common\service\open_platform\{OpenStore,OpenException};
 use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 function denied(callable $call){try{$call();}catch(\InvalidArgumentException|OpenException){return;}throw new \RuntimeException('Expected rejection');}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback fixture only');
 $dir=public_path().'uploads/agent-document-test-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);$temp=$dir.'/input';
 Db::startTrans();try{
  foreach(['txt','md','markdown','csv'] as $ext){file_put_contents($temp,"\xEF\xBB\xBF开场,主持人\r\n介绍,负责人");check(D::extract($temp,'流程.'.$ext)==="开场,主持人\n介绍,负责人",'Text normalization failed');}
  file_put_contents($temp,mb_convert_encoding('中文测试','GB18030','UTF-8'));check(D::extract($temp,'中文.txt')==='中文测试','Chinese encoding lost');
  file_put_contents($temp,"\xFF\xFE".mb_convert_encoding('中文测试','UTF-16LE','UTF-8'));check(D::extract($temp,'中文.txt')==='中文测试','UTF16 text lost');
  foreach(["binary\x00payload",str_repeat('字',D::MAX_CHARS+1),''] as $body){file_put_contents($temp,$body);denied(fn()=>D::extract($temp,'bad.txt'));}
  file_put_contents($temp,'%PDF-fake');denied(fn()=>D::extract($temp,'fake.pdf'));denied(fn()=>D::extract($temp,'fake.docx'));
  $word=$dir.'/word.docx';$zip=new \ZipArchive();$zip->open($word,\ZipArchive::CREATE);
  $xml='<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>会议流程</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>09:00</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>开场</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:p><w:del><w:r><w:t>删除的文字</w:t></w:r></w:del><w:r><w:t>结束</w:t></w:r></w:p></w:body></w:document>';
  $zip->addFromString('word/document.xml',$xml);$zip->close();$text=D::extract($word,'流程.docx');check(str_contains($text,"09:00\t开场")&&!str_contains($text,'删除的文字')&&str_ends_with($text,'结束'),'DOCX paragraphs/table/revisions not extracted');
  foreach(['<!DOCTYPE w:document [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$xml,str_repeat('a',4194305),'<broken>'] as $bad){$zip->open($word);$zip->addFromString('word/document.xml',$bad);$zip->close();denied(fn()=>D::extract($word,'bad.docx'));}
  $t=1999999908;$u=$t;$now=time();
  $s=AigcLlmSession::create(['tenant_id'=>$t,'user_id'=>$u,'title'=>'uploaded document fixture','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
  $base=['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'token_usage_json'=>[],'finish_reason'=>''];
  $makeRun=function(array $attachments,?int $root=null)use($t,$u,$s,$now,$base){
   $m=AigcLlmMessage::create($base+['role'=>'user','content'=>'总结上传的会议流程表','seq'=>1,'status'=>'done','parent_user_message_id'=>0]);
   $a=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'','seq'=>2,'status'=>'streaming','parent_user_message_id'=>$m['id']]);
   $id=S::table('run')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'session_id'=>$s['id'],'message_id'=>$a['id'],'request_id'=>'doc-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('a',64),'content'=>$m['content'],'preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'selected-model']]),'attachments'=>S::encode($attachments),'status'=>'running','phase'=>'chat','reply'=>'','lease_token'=>'fixture','lease_until'=>$now+1500,'create_time'=>$now,'update_time'=>$now]);
   S::table('run')->where('id',$id)->update(['preferences'=>S::encode(['mode'=>'ask','models'=>['text'=>'selected-model'],'_topic'=>['version'=>1,'decision'=>$root?'continue':'new','root_run_id'=>$root??$id]])]);return S::run($t,$u,(int)$id);
  };
  $body='开场'.str_repeat('流程内容。',1000).'观众体验';file_put_contents($temp,$body);clearstatcache();
  Fake::$file='of_'.bin2hex(random_bytes(16));
  OpenStore::table('file')->insert(['tenant_id'=>$t,'user_id'=>$u,'file_id'=>Fake::$file,'uri'=>substr($temp,strlen(public_path())),'name'=>'会议流程.txt','mime'=>'text/plain','size'=>strlen($body),'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
  $run=$makeRun([['file_id'=>Fake::$file]]);$page=C::readAsset($run,Fake::$file);$rest=C::readAsset($run,Fake::$file,4000);
  check($page['content_available']&&$page['next_offset']===4000&&$rest['next_offset']===null&&$page['content'].$rest['content']===$body,'Uploaded document pagination lost text');
  $chat=new \ReflectionMethod(E::class,'chat');$chat->invoke(null,$run);
  check(count(Fake::$requests)===3&&str_contains(S::encode(Fake::$requests[2]['messages']),'观众体验'),'Actual Neuron read loop did not receive the final page');
  $view=E::detail($t,$u,(int)$run['id']);check($view['status']==='success'&&$view['steps']===[],'Reading created a paid generation plan');
  $unrelated=$makeRun([]);denied(fn()=>C::readAsset($unrelated,Fake::$file));
  $resume=$makeRun([], (int)$run['id']);check(C::readAsset($resume,Fake::$file)['content_available'],'Returning to topic lost uploaded document');
  foreach([['tenant_id'=>$t+1],['user_id'=>$u+1],['session_id'=>$s['id']+9999]] as $other)denied(fn()=>C::readAsset(array_replace($run,$other),Fake::$file));
  OpenStore::owner('file',$t,$u)->where('file_id',Fake::$file)->update(['uri'=>'../.env']);check(!C::readAsset($run,Fake::$file)['content_available'],'Path traversal accepted');
  OpenStore::owner('file',$t,$u)->where('file_id',Fake::$file)->update(['uri'=>substr($temp,strlen(public_path()))]);unlink($temp);check(!C::readAsset($run,Fake::$file)['content_available'],'Missing source invented content');
  echo "Home Agent uploaded documents passed: text encodings, DOCX tables, malformed/oversize/XXE rejection, actual Neuron paged reads, topic/session/owner isolation and missing sources; rolled back\n";
 }finally{Db::rollback();foreach(glob($dir.'/*') as $path)@unlink($path);@rmdir($dir);}
}
