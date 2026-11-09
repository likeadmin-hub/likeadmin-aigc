<?php
// Explicit local-only integration test. All fixture rows roll back; no provider is called.
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App();$app->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL '.get_class($e).': '.mb_substr($e->getMessage(),0,200).PHP_EOL);exit(1);});
use app\common\service\app\aigc_product_video\{ProductVideoService as S,ProductVideoJobs as J,ProductVideoPolicy as P};
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use think\facade\Db;
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
function pvAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function pvReject(callable $call,string $why):void{try{$call();}catch(Throwable){return;}throw new RuntimeException($why);}
$dir=public_path().'uploads/product_video_test_'.bin2hex(random_bytes(8));mkdir($dir,0700,true);
Db::startTrans();
try{
    $uri='uploads/'.basename($dir).'/product.png';file_put_contents($dir.'/product.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>10,'name'=>'product-video-local-test','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    $models=S::models(1,'video')['models'];pvAssert(count($models)>0,'Callable video model required in local market');
    $m=$models[0];$full=S::models(1,'video',$m['id'])['models'][0];$spec=$full['specs'][0];
    $b=P::body(['images'=>[['uri'=>$uri]],'prompt'=>'展示外观','prompt_review_images'=>[$uri],'video_selection'=>['model_id'=>$m['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$spec['duration']]]);
    $p=S::save(1,1,['body'=>$b]);pvAssert($p['revision']===1,'Draft insert revision');
    pvReject(fn()=>S::owned(2,1,$p['id']),'Tenant isolation');pvReject(fn()=>S::owned(1,999999,$p['id']),'User isolation');
    pvReject(fn()=>S::save(1,1,['id'=>$p['id'],'revision'=>0,'body'=>$b]),'Stale write rejection');
    $args=['project_id'=>$p['id'],'revision'=>$p['revision'],'action'=>'video'];$q=J::estimate(1,1,$args);pvAssert(!$q['approximate'] && $q['user_charge_points']>=0,'Exact media quote');
    $request=$args+['quote_token'=>$q['quote_token'],'idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16))];$job=J::create(1,1,$request);$again=J::create(1,1,$request);pvAssert($job['id']===$again['id'],'Idempotent replay');
    pvAssert(!J::owned(1,1,$job['id'])['consumption_id'],'Queue creation must not submit provider');
    pvReject(fn()=>J::create(1,1,array_replace($request,['idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16))])),'Quote must be single-use');
    pvReject(fn()=>J::create(1,1,array_replace($request,['action'=>'product'])),'Idempotency key cannot cross actions');
    pvReject(fn()=>J::delete(1,1,$job['id']),'Pending job retention');
    $p=S::save(1,1,['id'=>$p['id'],'revision'=>$p['revision'],'body'=>array_replace($b,['prompt'=>'新的外观描述'])]);
    pvReject(fn()=>J::estimate(1,1,$args),'Stale project quote');
    $analysis=S::config(1)['analysis_models'];pvAssert(count($analysis)>0,'Local multimodal market model required');
    $p=S::save(1,1,['id'=>$p['id'],'revision'=>$p['revision'],'body'=>array_replace($b,['analysis_model_id'=>(string)$analysis[0]['id']])]);
    $textArgs=['project_id'=>$p['id'],'revision'=>$p['revision'],'action'=>'product'];$textQuote=J::estimate(1,1,$textArgs);pvAssert($textQuote['approximate'] && $textQuote['user_charge_points']===null,'Actual-usage analysis quote cannot invent a fixed amount');
    $textJob=J::create(1,1,$textArgs+['quote_token'=>$textQuote['quote_token'],'idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16))]);
    J::table()->where('id',$textJob['id'])->update(['status'=>'success','result_json'=>P::encode(['product_info'=>'白色商品样本'])]);
    $adopted=S::adopt(1,1,['job_id'=>$textJob['id'],'revision'=>$p['revision']]);pvAssert($adopted['body']['product_info']==='白色商品样本' && $adopted['revision']===$p['revision']+1,'Owned result adoption');
    pvReject(fn()=>S::adopt(1,1,['job_id'=>$textJob['id'],'revision'=>$adopted['revision']]),'An old result cannot overwrite a newer draft');
    J::table()->where('id',$job['id'])->update(['status'=>'unknown']);$recovered=J::recover(1,1,$job['id']);pvAssert($recovered['status']==='unknown' && !J::owned(1,1,$job['id'])['consumption_id'],'Uncertain reconciliation cannot resubmit a provider call');
    J::table()->where('id',$job['id'])->update(['consumption_id'=>2147480000,'next_run_at'=>time()+100]);
    $c=['id'=>2147480000,'app_code'=>P::APP,'tenant_id'=>1,'user_id'=>1];
    pvAssert(\app\common\service\app\aigc_product_video\ProductVideoWorker::wake($c,$job['id']),'Owned callback wake');
    pvReject(fn()=>\app\common\service\app\aigc_product_video\ProductVideoWorker::wake(array_replace($c,['tenant_id'=>2]),$job['id']),'Cross-tenant callback');
    pvAssert(!\app\common\service\app\aigc_product_video\ProductVideoWorker::wake(array_replace($c,['id'=>2147480001]),$job['id']),'Unrelated receipt cannot wake this execution');
    // Exercise the durable advanced pipeline without calling any provider.
    $advanced=array_replace($b,['generate_storyboard'=>true,'mode'=>'advanced','planning_model_id'=>(string)$analysis[0]['id']]);
    $ap=S::save(1,1,['body'=>$advanced]);$aa=['project_id'=>$ap['id'],'revision'=>$ap['revision'],'action'=>'analysis'];
    $aq=J::estimate(1,1,$aa);pvAssert(count($aq['phased_costs'])===2,'Analysis stage quote must disclose text and image costs');
    $aj=J::create(1,1,$aa+['quote_token'=>$aq['quote_token'],'idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16))]);
    $root=J::owned(1,1,$aj['id']);
    \app\common\service\app\aigc_product_video\ProductVideoPipeline::run($root);
    \app\common\service\app\aigc_product_video\ProductVideoPipeline::run($root);
    $links=S::table('job_stage')->where('root_job_id',$aj['id'])->select()->toArray();pvAssert(count($links)===1,'Plan stage must never duplicate');
    $duration=(int)$spec['duration'];$data=['product_info'=>'白色商品','summary'=>'全貌到细节','video_prompt'=>'完整展示后特写','shots'=>[['start'=>0,'end'=>1,'description'=>'全貌'],['start'=>1,'end'=>$duration,'description'=>'细节']]];
    J::table()->where('id',$links[0]['child_job_id'])->update(['status'=>'success','billing_status'=>'settled','user_charge_points'=>2,'result_json'=>P::encode($data)]);
    \app\common\service\app\aigc_product_video\ProductVideoPipeline::run($root);
    $links=S::table('job_stage')->where('root_job_id',$aj['id'])->order('id')->select()->toArray();pvAssert(count($links)===2,'Storyboard follows the successful text stage');
    J::table()->where('id',$links[1]['child_job_id'])->update(['status'=>'failed','billing_status'=>'refunded']);
    \app\common\service\app\aigc_product_video\ProductVideoPipeline::run($root);
    $partial=J::owned(1,1,$aj['id']);pvAssert($partial['status']==='partial'&&(float)$partial['user_charge_points']===2.,'Image failure must preserve paid text result');
    $adopted=S::adopt(1,1,['job_id'=>$aj['id'],'revision'=>$ap['revision']]);pvAssert($adopted['plan_fresh']&&$adopted['body']['plan']['data']['video_prompt']===$data['video_prompt'],'Partial analysis remains usable');
    $snapshot=S::fromJob(1,1,$aj['id']);pvAssert($snapshot['id']!==$ap['id']&&$snapshot['revision']===1,'Re-edit copies immutable submitted data into a new draft');
    $forked=S::fromJob(1,1,$aj['id'],true);pvAssert($forked['plan_fresh']&&$forked['body']['plan']['data']['video_prompt']===$data['video_prompt'],'Completed analysis resumes with its result in a new draft');
    pvAssert(S::owned(1,1,$ap['id'])['revision']===$adopted['revision'],'Fork cannot overwrite original draft');
    // Default advanced planning is text only: no image quote or image generation stage.
    $textOnly=S::save(1,1,['body'=>array_replace($advanced,['generate_storyboard'=>false,'image_selection'=>['model_id'=>'unavailable_fixture']])]);
    $ta=['project_id'=>$textOnly['id'],'revision'=>$textOnly['revision'],'action'=>'analysis'];$tq=J::estimate(1,1,$ta);pvAssert(count($tq['phased_costs'])===1,'Default planning cannot silently charge for a storyboard image');
    $tj=J::create(1,1,$ta+['quote_token'=>$tq['quote_token'],'idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16))]);$tr=J::owned(1,1,$tj['id']);
    \app\common\service\app\aigc_product_video\ProductVideoPipeline::run($tr);$tl=S::table('job_stage')->where('root_job_id',$tj['id'])->find();
    J::table()->where('id',$tl['child_job_id'])->update(['status'=>'success','billing_status'=>'settled','user_charge_points'=>1,'result_json'=>P::encode($data)]);
    \app\common\service\app\aigc_product_video\ProductVideoPipeline::run($tr);pvAssert(J::owned(1,1,$tj['id'])['status']==='success'&&S::table('job_stage')->where('root_job_id',$tj['id'])->count()===1,'Text-only analysis completes without a hidden image stage');
    pvAssert(J::lists(1,1,['group'=>'video'])['count']>0&&!in_array('analysis',array_column(J::lists(1,1,['group'=>'video'])['lists'],'action'),true),'Video history excludes helper operations');
    pvAssert(!in_array('video',array_column(J::lists(1,1,['group'=>'helper'])['lists'],'action'),true),'Helper history excludes final videos');
    pvReject(fn()=>J::lists(1,1,['group'=>'invalid']),'History group must be validated');
    $legacyPlan=J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$ap['id'],'project_revision'=>1,'action'=>'plan','idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16)),'input_hash'=>str_repeat('b',64),'status'=>'success','request_json'=>P::encode(['snapshot'=>$advanced]),'receipt_json'=>'{}','result_json'=>P::encode($data),'create_time'=>time(),'update_time'=>time()]);
    $continued=S::fromJob(1,1,$legacyPlan,true);pvAssert($continued['body']['product_info']===$data['product_info']&&$continued['plan_fresh'],'Legacy plan resumes with its identified product facts');
    $wp=S::save(1,1,['body'=>array_replace($b,['prompt'=>''])]);$wa=['project_id'=>$wp['id'],'revision'=>$wp['revision'],'action'=>'write'];$wq=J::estimate(1,1,$wa);pvAssert($wq['approximate'],'Writing without an existing description uses disclosed actual-usage billing');
    $wj=J::create(1,1,$wa+['quote_token'=>$wq['quote_token'],'idempotency_key'=>'pv_local_'.bin2hex(random_bytes(16))]);J::table()->where('id',$wj['id'])->update(['status'=>'success','result_json'=>P::encode(['video_prompt'=>'展示商品全貌并推近细节'])]);
    $written=S::adopt(1,1,['job_id'=>$wj['id'],'revision'=>$wp['revision']]);pvAssert($written['body']['prompt']==='展示商品全貌并推近细节'&&$written['body']['prompt_review_images']===[$uri],'Writing fills the current description and its material review state');
    pvReject(fn()=>S::fromJob(2,1,$aj['id']),'Cross tenant history reuse');
    pvReject(fn()=>P::key('pipeline_1234567890123'),'Public idempotency cannot collide with internal stages');
    echo "PASS local ownership, optimistic drafts, market quote, idempotency and queue isolation\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
