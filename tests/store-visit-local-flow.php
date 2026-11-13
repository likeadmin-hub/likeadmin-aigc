<?php
// Local transactional fixtures only. No provider or paid generation is called.
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).': '.mb_substr($e->getMessage(),0,300).' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);exit(1);});
use app\common\service\app\aigc_store_visit\{StoreVisitService as S,StoreVisitJobs as J,StoreVisitPolicy as P,StoreVisitCatalog as C,StoreVisitWorker as W,StoreVisitTemplates as T};
use app\common\service\ai\AiTaskJobService as Queue;
use think\facade\Db;
if(getenv('STORE_VISIT_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local database opt-in required');
function scAssert(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function scFails(callable $f,string $why):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($why);}
$folder='uploads/store_visit_test_'.bin2hex(random_bytes(8));$dir=public_path().$folder;mkdir($dir,0700,true);$uri=$folder.'/product.png';file_put_contents($dir.'/product.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
Db::startTrans();
try{
 Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>10,'name'=>'store-visit-local-test','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
 $options=S::config(1);$styles=array_values(array_filter($options['styles'],static fn($s)=>$s['id']!==P::NO_STYLE));scAssert(count($styles)>0&&count($options['genres'])===8,'Configured styles and narrative types');
 scAssert($options['styles'][0]['id']===P::NO_STYLE&&$options['styles'][0]['name']==='无风格','No style is available independently of tenant cover uploads');
 $m=C::models(1)[0];$m=C::models(1,(string)$m['id'])[0];$spec=$m['specs'][0];$seconds=(int)$spec['duration'];
 $userInfo="  商品名：用户确认的样本™\n卖点：便于收纳；编号 A-01，保留原文。  \n";
 $b=P::body(['images'=>[['uri'=>$uri,'role'=>'store','reference_slot_id'=>'product_slot'],['uri'=>$uri,'role'=>'person','reference_slot_id'=>'person_slot']],'product_info'=>$userInfo,'requirements'=>'一次暖心误会','story_type'=>'comedy','style_id'=>(string)$styles[0]['id'],'language'=>'中文','market_region'=>'中国','video_selection'=>['model_id'=>(string)$m['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$seconds]]);
 $project=S::save(1,1,['body'=>$b]);scAssert($project['revision']===1,'Independent draft');scAssert($project['body']['product_info']===$userInfo,'User name, selling points and whitespace survive saving verbatim');
 $legacyBody=$b;unset($legacyBody['product_observations']);scAssert(P::inputKey($legacyBody)===P::inputKey($b),'Empty supplement keeps legacy script freshness keys');
 scFails(fn()=>P::body(['product_info'=>str_repeat(' ',3001)]),'Raw product text length must be bounded');scFails(fn()=>S::owned(2,1,$project['id']),'Tenant ownership');scFails(fn()=>S::owned(1,999999,$project['id']),'User ownership');scFails(fn()=>S::save(1,1,['id'=>$project['id'],'revision'=>0,'body'=>$b]),'Stale write');
 $bad=$b;$bad['style_id']='99999999999';scFails(fn()=>S::save(1,1,['body'=>$bad]),'Disabled or foreign styles');
 if($m['max_reference_images']>1){
   copy($dir.'/product.png',$dir.'/product2.png');$second=$folder.'/product2.png';Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>10,'name'=>'store-visit-second-image','uri'=>$second,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
   $multi=$b;$multi['images'][]=['uri'=>$second,'role'=>'store','reference_slot_id'=>'second_product_slot'];$multi['script']='依次展示 @图片1 和 @图片2，保持商品身份一致。';$multi=S::save(1,1,['body'=>$multi,'confirm_script'=>true]);
   scAssert(count($multi['body']['images'])===3,'A multi-reference model must preserve both owned images');$multiSelection=C::selection(1,$multi['body']);scAssert($multiSelection['generation_method']!=='image_to_video','Multiple references must not be submitted as one first frame');
   $multiQuote=J::estimate(1,1,['project_id'=>$multi['id'],'revision'=>$multi['revision'],'action'=>'video']);scAssert(isset($multiQuote['quote_token']),'Native admission must price the multi-image mode without a provider call');
 }
 $args=['project_id'=>$project['id'],'revision'=>1,'action'=>'analysis'];$q=J::estimate(1,1,$args);scAssert($q['approximate']&&$q['user_charge_points']===null,'Analysis discloses actual-usage billing');
 // Admission checks use actual payer rates; all balance changes remain inside the rollback.
 foreach([['user','user_money'],['tenant','point_balance']] as [$table,$field]){
   $before=Db::name($table)->where('id',1)->value($field);Db::name($table)->where('id',1)->update([$field=>0]);
   $count=J::table()->count();$rejected=false;try{J::create(1,1,$args+['quote_token'=>$q['quote_token'],'idempotency_key'=>'sc_short_'.bin2hex(random_bytes(12))]);}catch(Throwable $e){scAssert(str_contains($e->getMessage(),'不足'),'Shortage rejected for unrelated reason');$rejected=true;}
   scAssert($rejected&&J::table()->count()===$count,'Shortage must not enqueue or consume quote');Db::name($table)->where('id',1)->update([$field=>$before]);
 }
 $request=$args+['quote_token'=>$q['quote_token'],'idempotency_key'=>'sc_test_'.bin2hex(random_bytes(16))];$j=J::create(1,1,$request);scAssert(J::create(1,1,$request)['id']===$j['id'],'Idempotent create');scFails(fn()=>J::create(1,1,array_replace($request,['idempotency_key'=>'sc_test_'.bin2hex(random_bytes(16))])),'Single-use quote');scFails(fn()=>J::delete(1,1,$j['id']),'Active job retention');
 $raw=J::owned(1,1,$j['id']);$r=P::json($raw['request_json']);$content=P::json($r['content']);scAssert($content['genre']['name']==='喜剧探店'&&$content['visual_style']['name']===$styles[0]['name'],'Selected genre and art style reach model content');scAssert($content['product_info']===$userInfo,'Exact user wording reaches the analysis model');scAssert(str_contains($r['system'],'不重复整理用户原文')&&str_contains($r['system'],'product_observations'),'Analysis prompt separates original facts from visual supplement');scAssert($content['requirements']==='一次暖心误会'&&$content['duration']===$seconds&&$content['language']==='中文','Actual creative inputs reach analysis');scAssert(!(int)$raw['consumption_id'],'Queued fixture must not contact provider');
 $script=['summary'=>'一场商品使用误会','characters'=>[['name'=>'同伴','appearance'=>'灰色外套']], 'shots'=>[['start'=>0,'end'=>$seconds,'action'=>'展示已确认商品并用一个小误会推进故事','dialogue'=>'','product_point'=>'白色外观','source_slots'=>['product_slot']]],'ending'=>'自然收尾'];
 $observations='图片中可见白色外观，未展示背面；具体材质无法从图片确认。';
 $result=W::parse($raw,['content'=>P::encode(['product_observations'=>$observations,'product_info'=>'模型试图改名，必须忽略','script'=>$script])]);
 scAssert(!array_key_exists('product_info',$result)&&$result['product_observations']===$observations,'AI output cannot become user-confirmed wording');
 $emptyResult=W::parse($raw,['content'=>P::encode(['product_observations'=>'','script'=>$script])]);scAssert($emptyResult['product_observations']==='','No extra visible facts is a valid analysis result');
 scFails(fn()=>W::parse($raw,['content'=>P::encode(['script'=>$script])]),'Missing supplement field must not silently succeed');
 $legacyResult=W::parse($raw,['content'=>P::encode(['product_info'=>'旧版识别文字','script'=>$script])]);scAssert($legacyResult['product_observations']==='旧版识别文字','Accepted old response is treated only as AI supplement');J::table()->where('id',$j['id'])->update(['status'=>'success','billing_status'=>'settled','result_json'=>P::encode($result)]);
 $adopted=S::adopt(1,1,['job_id'=>$j['id'],'revision'=>1]);scAssert($adopted['script_fresh']&&$adopted['body']['product_info']===$userInfo&&$adopted['body']['product_observations']===$observations,'Atomic script/supplement adoption preserves original user text');scFails(fn()=>S::adopt(1,1,['job_id'=>$j['id'],'revision'=>1]),'Late analysis cannot overwrite');
 $vargs=['project_id'=>$project['id'],'revision'=>$adopted['revision'],'action'=>'video'];$vq=J::estimate(1,1,$vargs);scAssert(!$vq['approximate']&&$vq['user_charge_points']>=0,'Native video quote');$vj=J::create(1,1,$vargs+['quote_token'=>$vq['quote_token'],'idempotency_key'=>'sc_test_'.bin2hex(random_bytes(16))]);$vraw=J::owned(1,1,$vj['id']);$vr=P::json($vraw['request_json']);scAssert(str_contains($vr['prompt'],$styles[0]['name'])&&str_contains($vr['prompt'],'喜剧探店'),'Final generation keeps actual style and genre');scAssert(str_contains($vr['prompt'],$userInfo)&&str_contains($vr['prompt'],$observations),'Video generation includes both original user wording and separate image observations');scAssert(count($vr['reference_assets'])===2&&!(int)$vraw['consumption_id'],'Original references, no provider call');
 // Reserve/fail through the real shared ledger, with no submit transport.
 $ub=(float)Db::name('user')->where('id',1)->value('user_money');$tb=(float)Db::name('tenant')->where('id',1)->value('point_balance');
 $runtime=$vr['runtime'];$payload=array_intersect_key($vr,array_flip(['prompt','reference_images','reference_assets','selected_mentions']))+$vr['selection'];
 $reserved=$runtime::reserve(1,1,P::APP,'video','aigc_store_visit_job',(string)$vj['id'],$vr['selection'],$payload);
 J::table()->where('id',$vj['id'])->update(['consumption_id'=>$reserved['consumption_id'],'app_task_id'=>$reserved['app_task_id'],'status'=>'running']);
 $consumption=Db::name('ai_consumption_log')->where('id',$reserved['consumption_id'])->find();
 scAssert($consumption['run_status']==='reserved'&&$consumption['upstream_task_id']==='','Reservation must not submit to a provider');
 scAssert((float)Db::name('user')->where('id',1)->value('user_money')<$ub&&(float)Db::name('tenant')->where('id',1)->value('point_balance')<$tb,'Both payer balances frozen');
 scFails(fn()=>W::wake(array_replace($consumption,['tenant_id'=>2]),$vj['id']),'Foreign callback must not wake a task');
 scAssert(W::wake($consumption,$vj['id']),'Owned callback wake-up');
 $runtime::fail((int)$reserved['consumption_id'],'local rollback fixture');W::reconcile(J::owned(1,1,$vj['id']));
 scAssert((float)Db::name('user')->where('id',1)->value('user_money')===$ub&&(float)Db::name('tenant')->where('id',1)->value('point_balance')===$tb,'Failure refunds both payers');
 scAssert(J::owned(1,1,$vj['id'])['billing_status']==='refunded','Refund reaches independent job');
$queue=Db::name('ai_task_job')->where('idempotency_key','store_visit:'.$vj['id'])->find();$queue['payload']=P::json($queue['payload']);scAssert(Queue::run($queue),'Registered general worker handles story job without a paid call');
 scAssert(J::lists(1,1,['group'=>'video','project_id'=>$project['id']])['count']===1&&J::lists(1,1,['group'=>'helper','project_id'=>$project['id']])['count']===1,'Separate helper and final histories');
 $new=S::fromJob(1,1,$j['id'],true);scAssert($new['id']!==$project['id']&&$new['body']['script']['summary']===$script['summary']&&$new['body']['product_info']===$userInfo&&$new['body']['product_observations']===$observations,'Analysis reuse creates a distinct draft');
 $legacyProject=S::save(1,1,['body'=>$b]);
 $legacyJob=$raw;unset($legacyJob['id']);$legacyJob['project_id']=$legacyProject['id'];$legacyJob['project_revision']=1;$legacyJob['idempotency_key']='sc_legacy_'.bin2hex(random_bytes(16));$legacyJob['status']='success';$legacyJob['billing_status']='settled';$legacyJob['result_json']=P::encode(['product_info'=>'旧版模型整理的商品信息','script'=>$script]);
 $legacyJobId=J::table()->insertGetId($legacyJob);$legacyAdopted=S::adopt(1,1,['job_id'=>$legacyJobId,'revision'=>1]);
 scAssert($legacyAdopted['body']['product_info']===$userInfo&&$legacyAdopted['body']['product_observations']==='旧版模型整理的商品信息','Persisted legacy result never replaces original input');
 $legacyCopy=S::fromJob(1,1,$legacyJobId,true);scAssert($legacyCopy['body']['product_info']===$userInfo&&$legacyCopy['body']['product_observations']==='旧版模型整理的商品信息','Legacy analysis reuse preserves the request snapshot original');
 $emptyBody=$b;$emptyBody['product_info']='';$emptyProject=S::save(1,1,['body'=>$emptyBody]);$emptyJob=$legacyJob;$emptyJob['project_id']=$emptyProject['id'];$emptyJob['idempotency_key']='sc_empty_'.bin2hex(random_bytes(16));$emptyRequest=P::json($raw['request_json']);$emptyRequest['snapshot']=$emptyProject['body'];$emptyJob['request_json']=P::encode($emptyRequest);$emptyJob['result_json']=P::encode($result);$emptyJobId=J::table()->insertGetId($emptyJob);
 $emptyAdopted=S::adopt(1,1,['job_id'=>$emptyJobId,'revision'=>1]);scAssert($emptyAdopted['body']['product_info']===''&&$emptyAdopted['body']['product_observations']===$observations&&$emptyAdopted['script_fresh'],'Image-only analysis keeps the optional user input empty and supplies observations/script');
 $videoUri=$folder.'/preview.mp4';copy(dirname(__DIR__,2).'/web/pc/public/media/aigc-preview.mp4',$dir.'/preview.mp4');Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>20,'name'=>'store-visit-test-video','uri'=>$videoUri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
 $video=['video_uri'=>$videoUri,'uri'=>$videoUri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','width'=>640,'height'=>480,'duration'=>1];J::table()->where('id',$vj['id'])->update(['status'=>'success','billing_status'=>'settled','result_json'=>P::encode(['videos'=>[$video]])]);
 $template=T::fromJob(1,$vj['id'],['title'=>'本地剧情模板','status'=>1]);scAssert(T::fromJob(1,$vj['id'],['title'=>'本地剧情模板'])['id']===$template['id'],'Template creation idempotency');$remix=T::remix(1,1,$template['id']);scAssert($remix['id']!==$project['id']&&$remix['script_fresh']&&$remix['body']['product_info']===$userInfo&&$remix['body']['product_observations']===$observations,'Template restores complete script and creates a distinct draft');
 $assets=app\common\service\WorkspaceStoreVisitAssetService::lists(1,1,1);scAssert(in_array('store_visit:task:'.$vj['id'].':1',array_column($assets['lists'],'ref'),true),'Unified asset adapter');scFails(fn()=>T::detail(2,$template['id'],true),'Tenant template isolation');
 $metadata=T::save(0,['id'=>$template['id'],'title'=>'平台修改名称','sort'=>5]);scAssert($metadata['title']==='平台修改名称','Platform can maintain tenant template metadata without foreign upload receipts');
 $settings=S::settings(1);foreach($settings['genres'] as &$g)if($g['code']==='comedy')$g['instruction']='替换后的新规则';unset($g);S::setup(1,['genres'=>$settings['genres']]);
 $historical=T::remix(1,1,$template['id']);scAssert($historical['body']['story_type']==='custom'&&str_contains($historical['body']['custom_story'],'喜剧探店'),'Historical templates must retain original narrative rules after configuration changes');
 $plainBody=$historical['body'];$plainBody['script']='同事误会商品不见了，镜头展示 @图片1，找到后轻松收尾。';
 $plainDraft=S::save(1,1,['body'=>$plainBody,'confirm_script'=>true]);scAssert($plainDraft['script_fresh']&&$plainDraft['body']['script']===$plainBody['script'],'Direct text draft must round-trip without a hidden storyboard');
 $plainQuote=J::estimate(1,1,['project_id'=>$plainDraft['id'],'revision'=>$plainDraft['revision'],'action'=>'video']);scAssert(isset($plainQuote['quote_token']),'Native quote must accept direct text');
 $updated=T::save(1,['id'=>$template['id'],'title'=>'文本脚本模板','configuration'=>$plainBody]);scAssert($updated['configuration']['script']===$plainBody['script'],'Template must preserve direct text');
 $copied=T::remix(1,1,$template['id']);scAssert($copied['script_fresh']&&$copied['body']['script']===$plainBody['script'],'Remix must restore the same editable text');
 $none=$plainBody;$none['style_id']=P::NO_STYLE;$none['custom_style']='旧风格必须清除';$none['requirements']='保留复古胶片质感';$none['script']='复古胶片风格，人物拿起 @图片1，自然收尾。';
 $noneDraft=S::save(1,1,['body'=>$none,'confirm_script'=>true]);$noneBody=$noneDraft['body'];scAssert($noneDraft['script_fresh']&&$noneBody['custom_style']===''&&$noneBody['style_snapshot']['mode']==='auto','No-style draft is real, fresh and unconstrained');
 scAssert(str_contains(P::prompt($noneBody),'未指定预设')&&str_contains(P::prompt($noneBody),$none['script']),'No-style native prompt keeps the exact user visual description');
 $noneArgs=['project_id'=>$noneDraft['id'],'revision'=>$noneDraft['revision'],'action'=>'analysis'];$noneQuote=J::estimate(1,1,$noneArgs);$noneJob=J::create(1,1,$noneArgs+['quote_token'=>$noneQuote['quote_token'],'idempotency_key'=>'sc_test_'.bin2hex(random_bytes(16))]);
 $noneRaw=J::owned(1,1,$noneJob['id']);$noneRequest=P::json($noneRaw['request_json']);$noneContent=P::json($noneRequest['content']);scAssert($noneContent['visual_style']['mode']==='auto'&&$noneContent['existing_script']===$none['script']&&$noneContent['requirements']===$none['requirements']&&!(int)$noneRaw['consumption_id'],'AI receives no preset plus both user style inputs without a provider call');
 J::estimate(1,1,['project_id'=>$noneDraft['id'],'revision'=>$noneDraft['revision'],'action'=>'video']);
 T::save(1,['id'=>$template['id'],'configuration'=>$noneBody]);$noneRemix=T::remix(1,1,$template['id']);scAssert($noneRemix['script_fresh']&&$noneRemix['body']['style_id']===P::NO_STYLE&&$noneRemix['body']['custom_style']===''&&$noneRemix['body']['script']===$none['script'],'Templates preserve no style through historical copy and remix');
 S::setup(1,['default_style_id'=>P::NO_STYLE]);scAssert(S::config(1)['default_style_id']===P::NO_STYLE,'Tenant can explicitly default to no style');
 echo "PASS transactional draft ownership, native model quote, genre/style context, idempotency, queue dispatch, double-payer freeze/refund, stale adoption, historical template remix and asset integration; no provider called\n";
}catch(Throwable $error){$fixtureFailure=$error;throw $error;}finally{try{Db::rollback();}catch(Throwable $rollbackError){if(!isset($fixtureFailure))throw $rollbackError;}foreach(['product.png','product2.png','preview.mp4'] as $name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);rmdir($dir);}
