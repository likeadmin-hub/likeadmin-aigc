<?php
/** Owned local admission state/ownership checks. No supplier transport or billing. */
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
require $root.'/tests/Support/MarketWorkerFixture.php';
use app\common\service\ai\GenerationSubmission as Admission;
use app\common\model\ai\AiAppTask;
use app\common\model\app\aigc_image\AigcImageTask;
use think\facade\Db;
MarketWorkerFixture::check(getenv('AIGC_SUBMISSION_DB_TESTS')==='1','Explicit local opt-in required');
$f=null;$report=['scope'=>'Private SQL tasks and receipt state only; no provider or billing calls','supplier_calls'=>0,'production_wallet_writes'=>0];
try {
 $f=new MarketWorkerFixture(config('database'),['tenant','user','ai_app_task','aigc_image_task','ai_generation_submission']);
 $t=$f->tenant;$u=$f->user;$op='app.aigc_image.generate/index';$n=0;$last=null;
 $create=function($owner=null)use($f,$t,$u,&$n,&$last){$owner=$owner??$u;$n++;$a=AiAppTask::create(['tenant_id'=>$t,'user_id'=>$owner,'task_no'=>'S'.bin2hex(random_bytes(12)),'app_code'=>'aigc_image','idempotency_key'=>bin2hex(random_bytes(16)),'status'=>'running']);$last=AigcImageTask::create(['tenant_id'=>$t,'user_id'=>$owner,'app_task_id'=>$a['id'],'status'=>'running','prompt'=>'Synthetic admission state']);return ['task_id'=>(int)$last['id'],'generation_task'=>['task_ref'=>'app_task:'.$a['id']]];};
 $count=fn()=>Db::name('ai_app_task')->count();
 $run=fn($key,$p,$fn,$owner=null)=>Admission::run($t,$owner??$u,$op,$key,$p,$fn)->getData();
 $p=['prompt'=>'Synthetic','sound'=>false,'refs'=>['a','b']];$key='owned-original-request-01';
 $first=$run($key,$p,fn()=>json(['code'=>1,'data'=>$create()]));$second=$run($key,$p,static function(){throw new RuntimeException('Duplicate dispatch');});
 MarketWorkerFixture::check($first['data']===$second['data']&&$n===1,'Same key re-entered dispatch');$report['same_key_same_receipt_once']=true;
 $changed=$run($key,array_replace($p,['sound'=>'']),static function(){throw new RuntimeException('Conflicting dispatch');});MarketWorkerFixture::check($changed['code']===0&&$n===1,'Changed scalar was dispatched');$report['changed_payload_refused']=true;
 $fresh=$run('owned-new-attempt-02',$p,fn()=>json(['code'=>1,'data'=>$create()]));MarketWorkerFixture::check($fresh['data']!==$first['data']&&$n===2,'Explicit new key reused previous task');$report['new_key_new_owned_task']=true;
 $other=$run($key,$p,fn()=>json(['code'=>1,'data'=>$create($u+1)]),$u+1);MarketWorkerFixture::check($other['data']!==$first['data']&&$n===3,'Foreign owner reused original receipt');$report['same_key_foreign_owner_isolated']=true;
 $original=$first['data']['task_id'];Db::name('aigc_image_task')->where('id',$original)->update(['delete_time'=>time()]);$before=$count();$deleted=$run($key,$p,static function(){throw new RuntimeException('Deleted task redispatch');});MarketWorkerFixture::check($deleted['code']===0&&$count()===$before,'Deleted task was recreated');$report['deleted_task_not_recreated']=true;
 $rejectKey='owned-local-rejection-03';$rejected=$run($rejectKey,$p,fn()=>json(['code'=>0,'data'=>[],'msg'=>'Local validation rejected']));$again=$run($rejectKey,$p,fn()=>json(['code'=>1,'data'=>$create()]));$cached=$run($rejectKey,$p,static function(){throw new RuntimeException('Accepted local retry redispatch');});MarketWorkerFixture::check($rejected['generation_submission']['state']==='rejected'&&$again['generation_submission']['state']==='accepted'&&$cached['data']===$again['data'],'Local refusal could not be corrected exactly once');$report['local_refusal_revalidated_then_accepted_once']=true;
 try{$run('owned-uncertain-request-04',$p,function()use($create){$create();throw new RuntimeException('Synthetic unknown acceptance');});throw new RuntimeException('Expected dispatch failure');}catch(RuntimeException $error){MarketWorkerFixture::check($error->getMessage()==='Synthetic unknown acceptance','Unexpected failure');}
 $before=$count();$unknown=$run('owned-uncertain-request-04',$p,static function(){throw new RuntimeException('Unknown request redispatch');});MarketWorkerFixture::check($unknown['generation_submission']['state']==='uncertain'&&$count()===$before&&Admission::key()==='','Uncertain request re-entered or leaked context');$report['unknown_not_redispatched_context_restored']=true;
 $row=Db::name('ai_generation_submission')->where('key_hash',hash('sha256','owned-uncertain-request-04'))->find();Db::name('ai_generation_submission')->where('id',$row['id'])->update(['status'=>'submitting','create_time'=>time()-86400*30,'update_time'=>time()-86400*30]);$stale=$run('owned-uncertain-request-04',$p,static function(){throw new RuntimeException('Expired request redispatch');});MarketWorkerFixture::check($stale['generation_submission']['state']==='submitting'&&$count()===$before,'Time expiry redispatched an uncertain request');$report['old_inflight_receipt_never_auto_expires']=true;
 $value=new class implements JsonSerializable {public int $calls=0;public function jsonSerialize():mixed{return ['serialization'=>++$this->calls];}};
 $serialized=$run('owned-lazy-response-05',$p,fn()=>json(['code'=>1,'data'=>$value]));$cached=$run('owned-lazy-response-05',$p,static function(){throw new RuntimeException('Lazy response redispatch');});MarketWorkerFixture::check($serialized['data']===$cached['data']&&$value->calls===1,'First and cached response serialized differently');$report['first_and_replay_share_one_serialization']=true;
 $ran=false;$name=$f->prefix.'ai_generation_submission';Db::execute('RENAME TABLE `'.$name.'` TO `'.$name.'_unavailable`');
 try{$closed=$run('owned-schema-unavailable-06',$p,function()use(&$ran){$ran=true;return json(['code'=>1,'data'=>[]]);});MarketWorkerFixture::check($closed['code']===0&&!$ran,'Missing receipt storage allowed generation');}finally{Db::execute('RENAME TABLE `'.$name.'_unavailable` TO `'.$name.'`');}
 $report['missing_schema_fails_before_generation']=true;
 $raw='synthetic-legacy-video-key';$legacy=sha1($t.'|aigc_video|video_generate|aigc_video_task|'.$raw.'|market_video');$legacyTask=AiAppTask::create(['tenant_id'=>$t,'user_id'=>$u,'task_no'=>'L'.bin2hex(random_bytes(12)),'app_code'=>'aigc_video','business_table'=>'aigc_video_task','idempotency_key'=>$legacy,'status'=>'running']);
 $resolve=fn($owner)=>app\common\service\power\MarketVideoRuntimeService::userRequestBusinessId($t,$owner,'aigc_video','video_generate','aigc_video_task',$raw);
 MarketWorkerFixture::check($resolve($u)===$raw&&$resolve($u+1)!==$raw&&$legacyTask['idempotency_key']===$legacy,'Legacy video reservation changed or crossed owners');$report['owned_legacy_video_key_preserved_other_owner_separated']=true;
 $report['private_task_rows']=$count();$report['private_receipt_rows']=Db::name('ai_generation_submission')->count();
} catch(Throwable $error){$report['failure']=$error->getMessage();$failed=true;}
finally{if($f){$f->cleanup();$report['private_tables_and_files_removed']=true;}}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";if(isset($failed))exit(1);
