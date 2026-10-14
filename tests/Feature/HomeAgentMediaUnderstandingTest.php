<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\app\aigc_llm\agent\{AgentMediaReader as M,AgentTranscriptionTool as T,AgentStore as S};
use think\facade\Db;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject(callable $call){try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected bounded rejection');}
function pdfFixture(string $path):void{
 $stream="BT /F1 18 Tf 60 720 Td (Launch starts 09:15. Host: Lin. Code: Lime-7246.) Tj ET";
 $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',"<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream"];
 $out="%PDF-1.4\n";$offsets=[0];foreach($objects as $i=>$obj){$offsets[]=strlen($out);$out.=($i+1)." 0 obj\n$obj\nendobj\n";}$xref=strlen($out);$out.="xref\n0 6\n0000000000 65535 f \n";foreach(array_slice($offsets,1) as $offset)$out.=sprintf('%010d 00000 n ',$offset)."\n";$out.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";file_put_contents($path,$out);
}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback fixture only');
$dir=sys_get_temp_dir().'/agent-understanding-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
 pdfFixture($dir.'/doc.pdf');$page=M::pdf($dir.'/doc.pdf',$dir,0);check(str_contains($page['content'],'Lime-7246')&&$page['total_pages']===1&&$page['next_offset']===null,'Native PDF text/pages lost');check(str_starts_with(current($page['visuals']),'data:image/jpeg;base64,'),'Mixed/scanned page visual missing');
 file_put_contents($dir.'/bad.pdf','not a PDF');reject(fn()=>M::pdf($dir.'/bad.pdf',$dir,0));reject(fn()=>M::command(['/bin/sh','-c','true']));
 M::command(['/usr/bin/ffmpeg','-v','error','-y','-f','lavfi','-i','color=c=blue:s=320x180:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-c:v','mpeg4','-c:a','aac','-shortest',$dir.'/clip.mp4']);
 $video=M::video($dir.'/clip.mp4',$dir,0);check($video['has_audio']&&$video['has_video']&&count($video['visuals'])===4&&$video['next_offset']===null,'Video sampling/track information wrong');check(filesize(M::audio($dir.'/clip.mp4',$dir))>1000,'Audio track not extracted');
 Db::startTrans();try{
  $now=time();$p=['mode'=>'ask','models'=>['text'=>'fixture']];
  $id=S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>0,'message_id'=>0,'request_id'=>'understanding-'.bin2hex(random_bytes(10)),'payload_hash'=>str_repeat('f',64),'content'=>'理解素材','preferences'=>S::encode($p),'attachments'=>'[]','status'=>'running','phase'=>'tools','reply'=>'','lease_token'=>'test','lease_until'=>$now+600,'create_time'=>$now,'update_time'=>$now]);
  $quote=T::quote(1);$prepared=['run_id'=>$id,'inputs'=>['file_id'=>'of_'.str_repeat('f',32)],'quote'=>$quote];
  $sid=S::table('step')->insertGetId(['tenant_id'=>1,'user_id'=>1,'run_id'=>$id,'step_key'=>hash('sha256','stt-fixture'),'title'=>'转写验收','tool_code'=>T::CODE,'prepared'=>'{}','status'=>'submitting','result'=>'','create_time'=>$now,'update_time'=>$now]);$prepared['step_id']=$sid;
  $reserve=new ReflectionMethod(T::class,'reserve');$finish=new ReflectionMethod(T::class,'finish');$uncertain=new ReflectionMethod(T::class,'uncertain');
  $before=(float)Db::name('user')->where('id',1)->value('user_money');$r=$reserve->invoke(null,1,1,$prepared);$repeat=$reserve->invoke(null,1,1,$prepared);check(!$repeat['fresh']&&$repeat['task_id']===$r['task_id'],'STT replay created another charge');
  $finish->invoke(null,$r['consumption_id'],true,['file_id'=>$prepared['inputs']['file_id'],'text'=>'Launch starts at nine fifteen.']);$finish->invoke(null,$r['consumption_id'],true,['text'=>'same result']);$uncertain->invoke(null,$r['consumption_id']);
  $after=(float)Db::name('user')->where('id',1)->value('user_money');check(abs(($before-$after)-$quote['user'])<0.001,'STT charged more than one request');check(T::query(1,1,$r['task_id'])['status']==='success','Terminal success downgraded');reject(fn()=>T::query(1,2,$r['task_id']));
  S::table('step')->where('id',$sid)->update(['status'=>'success']);$run=S::run(1,1,$id);check(T::resume($run),'STT completion did not resume answer');$new=S::run(1,1,$id);check($new['phase']==='chat'&&$new['status']==='queued'&&!T::resume($new),'STT continuation repeated');
 }finally{Db::rollback();}
 echo "Home Agent media understanding passed: PDF extraction/rendering, invalid data/command rejection, video sampling/audio extraction, owned STT receipts, one-time billing and continuation; rolled back\n";
}finally{foreach(glob($dir.'/*') as $path)@unlink($path);@rmdir($dir);}
