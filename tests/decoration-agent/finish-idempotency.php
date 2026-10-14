<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error):void{fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentCatalog as C};
$n=0;function finishCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;}
Db::startTrans();try{
 $session=(int)Db::name('decorate_agent_session')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'template_id'=>0,'page_id'=>0,'preferences'=>'{}','facts'=>'[]']);
 $input=['scope'=>'page','attachments'=>[],'sources'=>['generate'],'image_model_selection'=>[],'budget_points'=>25];$reply='素材已完成，请截图复审。 Crop ready';
 $run=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session,'status'=>'running','input_json'=>S::json($input),'context_json'=>'{}','result_json'=>'{}','events_json'=>'[]','lease_token'=>'fixture-retained','lease_expire_time'=>time()+600,'idempotency_key'=>'finish-fixture:'.bin2hex(random_bytes(8))]);
 foreach([[2,1,$session,'assistant'],[1,2,$session,'assistant'],[1,1,$session+1,'assistant'],[1,1,$session,'user']] as [$tenant,$admin,$ownerSession,$role])Db::name('decorate_agent_message')->insert(['tenant_id'=>$tenant,'admin_id'=>$admin,'session_id'=>$ownerSession,'run_id'=>$run,'role'=>$role,'content'=>$reply,'attachments'=>'[]']);
 $count=static fn():int=>(int)Db::name('decorate_agent_message')->where(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session,'run_id'=>$run,'role'=>'assistant'])->count();
 $finish=new ReflectionMethod(S::class,'finish');$finish->setAccessible(true);$finish->invoke(null,$run,'completed',['reply'=>$reply]);$finish->invoke(null,$run,'completed',['reply'=>$reply]);
 finishCheck($count()===1,'repeated finish inserts one exact same-owner assistant reply, unaffected by foreign/user duplicates');
 $payload=['data'=>[C::create('banner','hero')],'meta'=>[],'name'=>'fixture','new_page'=>false];$candidate=(int)Db::name('decorate_agent_candidate')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session,'run_id'=>$run,'status'=>'ready','base_hash'=>S::hash($payload),'base_json'=>S::json($payload),'payload_json'=>S::json($payload),'changes_json'=>'[]']);Db::name('decorate_agent_run')->where('id',$run)->update(['candidate_id'=>$candidate]);
 $slot=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session,'run_id'=>$run,'candidate_id'=>$candidate,'slot_key'=>'hero:content.data.0.image','status'=>'failed','request_json'=>S::json(['widget_id'=>'hero','field'=>'content.data.0.image','prompt'=>'fixture','old_value'=>'']),'result_json'=>'{}']);
 $retry=S::retryImage(1,1,['slot_id'=>$slot]);finishCheck((int)$retry['slot_id']!==$slot&&Db::name('decorate_agent_run')->where('id',$run)->value('status')==='waiting_images','real image retry retains the original conversation run');
 // Simulate a known pre-provider image failure, then execute actual final polling closure without any paid submit.
 Db::name('decorate_agent_image_slot')->where('id',$retry['slot_id'])->update(['status'=>'failed']);
 $process=new ReflectionMethod(S::class,'processImages');$process->setAccessible(true);$process->invoke(null,Db::name('decorate_agent_run')->where('id',$run)->find(),$input);
 finishCheck($count()===1&&Db::name('decorate_agent_run')->where('id',$run)->value('status')==='completed','retry completion with inherited previous reply does not duplicate history');
 $finish->invoke(null,$run,'completed',['reply'=>$reply,'warnings'=>['different terminal warning']]);$events=S::decode(Db::name('decorate_agent_run')->where('id',$run)->value('events_json'));$last=end($events);
 finishCheck($count()===1&&($last['data']['warnings'][0]??'')==='different terminal warning','same message suppression preserves distinct terminal warning event');
 $finish->invoke(null,$run,'completed',['reply'=>'素材已完成，请截图复审。 crop ready']);finishCheck($count()===2,'case-different content remains a distinct assistant message despite database collation');
 $finish->invoke(null,$run,'failed',['error'=>'具体失败警告']);finishCheck($count()===3,'different terminal error remains a visible conversation message');
 $other=(int)Db::name('decorate_agent_run')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>$session,'input_json'=>'{}','context_json'=>'{}','result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'finish-other:'.bin2hex(random_bytes(8))]);$finish->invoke(null,$other,'completed',['reply'=>$reply]);finishCheck(Db::name('decorate_agent_message')->where(['run_id'=>$other,'role'=>'assistant'])->count()===1,'different runs may legitimately produce the same reply');
 $row=Db::name('decorate_agent_run')->where('id',$run)->find();finishCheck($row['lease_token']==='fixture-retained'&&(float)$row['spent_points']===0.0,'completion dedup preserves leases and zero-charge fixture billing state');
}finally{Db::rollback();}
echo "finish idempotency: $n checks passed (real DB rollback, no provider calls)\n";
