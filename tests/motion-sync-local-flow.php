<?php
/** Local rollback acceptance; real shared billing, owned generated fixtures, no upstream submission. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();request()->withServer(['HTTP_HOST'=>'motion-fixture.example','HTTPS'=>'on','SERVER_PORT'=>443]);
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_motion_sync\{MotionSyncService as S,MotionSyncJobs as J,MotionSyncTemplates as T};
use app\common\service\power\{MarketMotionSyncRuntimeService as M,MarketVideoRuntimeService as V};
use app\common\service\ai\MarketVideoSubmissionService as Admission;
use app\common\model\power\PowerMarketProduct;
if(getenv('MOTION_SYNC_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.hostname')!=='127.0.0.1'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local opt-in required');
function msCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function msReject(callable $f,string $why):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($why);}
$dir=public_path().'uploads/ms_fixture_'.bin2hex(random_bytes(8));mkdir($dir,0700,true);Db::startTrans();
try{
    $proc=proc_open(['/usr/bin/ffmpeg','-v','error','-f','lavfi','-i','color=c=white:s=320x320:d=3.2','-c:v','libx264','-pix_fmt','yuv420p',$dir.'/motion.mp4'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);msCheck(proc_close($proc)===0,'Fixture video failed: '.$error);
    file_put_contents($dir.'/character.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $image='uploads/'.basename($dir).'/character.png';$video='uploads/'.basename($dir).'/motion.mp4';
    foreach([[10,$image],[20,$video]] as [$type,$uri])Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>$type,'name'=>'Motion fixture','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    $ids=[];foreach(PowerMarketProduct::where('model_type','other')->select()->toArray() as $product)if(M::supportsProduct($product))$ids[]=$product['id'];msCheck(count($ids)===2,'Both synchronized model families required');
    Db::name('power_market_sku')->whereIn('product_id',$ids)->update(['sale_status'=>1,'status'=>1,'sale_points'=>0.1]);
    $skuIds=Db::name('power_market_sku')->whereIn('product_id',$ids)->column('id');Db::name('tenant_power_market_sku_price')->where('tenant_id',1)->whereIn('sku_id',$skuIds)->delete();
    $models=M::options(1);$model=$models[0];msCheck($model['available']&&count($model['specs'])===2,'Published specifications required');
    msCheck(!in_array($model['market_product_id'],array_column(V::options(1,'model'),'market_product_id')),'Other models leaked into generic video');
    $body=['image'=>['uri'=>$image],'video'=>['uri'=>$video,'duration'=>9999],'model_key'=>$model['model_key'],'quality'=>$model['specs'][0]['quality'],'orientation'=>'video','prompt'=>'保持角色外观，同步舞蹈动作'];
    $project=S::save(1,1,['body'=>$body]);msCheck($project['body']['video']['duration']<4,'Browser duration was trusted');
    msReject(fn()=>S::owned(2,1,$project['id']),'Cross-tenant draft accepted');msReject(fn()=>S::owned(1,9000000,$project['id']),'Cross-user draft accepted');
    msReject(fn()=>S::save(1,1,['id'=>$project['id'],'revision'=>0,'body'=>$body]),'Stale draft accepted');
    $args=['project_id'=>$project['id'],'revision'=>$project['revision']];$quote=J::estimate(1,1,$args);msCheck($quote['quantity']>=3&&$quote['quantity']<4,'Measured duration quote mismatch');
    $userBefore=(float)Db::name('user')->where('id',1)->value('user_money');$tenantBefore=(float)Db::name('tenant')->where('id',1)->value('point_balance');
    $params=$args+['quote_token'=>$quote['token'],'idempotency_key'=>'ms_fixture_'.bin2hex(random_bytes(12))];$task=J::create(1,1,$params);$again=J::create(1,1,$params);msCheck($again['id']===$task['id'],'Duplicate submission created another task');
    $row=J::owned(1,1,$task['id']);$c=Db::name('ai_consumption_log')->where('id',$row['consumption_id'])->find();msCheck($c['run_status']==='reserved'&&$c['upstream_task_id']==='','Create performed upstream work');
    msCheck((float)Db::name('user')->where('id',1)->value('user_money')<$userBefore,'User points not frozen');msCheck((float)Db::name('tenant')->where('id',1)->value('point_balance')<$tenantBefore,'Tenant points not frozen');
    msCheck(Db::name('ai_task_job')->where(['consumption_id'=>$c['id'],'job_type'=>'motion_sync_submit'])->count()===1,'Initial durable job missing');
    $captured=S::json($row['request_json']);$prepared=V::prepareRequest(S::json($c['price_snapshot']),$captured['request']);$wire=\app\common\service\power\MarketModelTaskRequest::payload($prepared->nativeInput);
    msCheck($wire['mode']===$body['quality']&&$wire['character_orientation']==='video'&&count($wire['input_urls'])===1&&count($wire['video_urls'])===1,'Native API payload does not match the form');
    msCheck(!array_intersect(['duration','aspect_ratio','generate_audio','keep_original_sound','audio_urls','first_frame_image','last_frame_image'],array_keys($wire)),'Undeclared capabilities leaked to the provider wire');
    msReject(fn()=>J::delete(1,1,$task['id']),'Queued task was deleted');msReject(fn()=>J::create(1,1,array_replace($params,['idempotency_key'=>'ms_other_'.bin2hex(random_bytes(12))])),'Consumed quote reused');
    $cancel=J::cancel(1,1,$task['id']);J::run((int)$c['id']);msCheck($cancel['status']==='canceled','Cancellation status mismatch');
    msCheck((float)Db::name('user')->where('id',1)->value('user_money')===$userBefore,'User refund mismatch');msCheck((float)Db::name('tenant')->where('id',1)->value('point_balance')===$tenantBefore,'Tenant refund mismatch');
    $quote=J::estimate(1,1,$args);$task=J::create(1,1,$args+['quote_token'=>$quote['token'],'idempotency_key'=>'ms_unknown_'.bin2hex(random_bytes(12))]);$row=J::owned(1,1,$task['id']);
    J::table()->where('id',$row['id'])->update(['submit_started_at'=>time(),'status'=>'running']);J::run((int)$row['consumption_id']);msCheck(J::owned(1,1,$row['id'])['status']==='unknown','Crashed submission was not held');
    msCheck(Admission::begin((int)$row['consumption_id']),'Admission fixture failed');msCheck(!Admission::begin((int)$row['consumption_id']),'Second transport authorized');
    msCheck(Admission::hold((int)$row['consumption_id'],'cURL error 28: timeout'),'Ambiguous submission refunded');J::syncConsumption(Db::name('ai_consumption_log')->where('id',$row['consumption_id'])->find(),(int)$row['id']);
    msReject(fn()=>J::delete(1,1,$row['id']),'Unknown task deleted');msReject(fn()=>J::cancel(1,1,$row['id']),'Unknown task canceled');msReject(fn()=>S::fromJob(1,1,$row['id']),'Unknown submission regenerated');
    $prior=$row;J::syncConsumption(array_replace($c,['id'=>$row['consumption_id'],'tenant_id'=>2]),(int)$row['id']);msCheck(J::owned(1,1,$row['id'])['status']==='unknown','Cross-tenant projection mutated task');
    $safe=J::dto(J::owned(1,1,$row['id']));msCheck(!isset($safe['upstream_task_id'],$safe['pricing_snapshot'])&&!isset($safe['configuration']['image']['file_id']),'Private diagnostics leaked');
    // Admin manual templates, complete remix, source-task snapshot, soft deletion.
    $uploads=[];foreach([[10,$image],[20,$video]] as [$type,$uri])$uploads[$type]=Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source_id'=>0,'source'=>0,'type'=>$type,'name'=>'Admin motion fixture','uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    $config=array_replace($body,['image'=>['file_id'=>$uploads[10]],'video'=>['file_id'=>$uploads[20]]]);
    $template=T::save(1,['title'=>'本地动作灵感','configuration'=>$config,'preview'=>['file_id'=>$uploads[20]],'sort'=>9]);
    $remix=T::remix(1,1,$template['id']);msCheck($remix['id']!==$project['id']&&$remix['body']['prompt']===$body['prompt']&&$remix['body']['orientation']==='video','Full independent remix failed');
    T::status(1,$template['id'],0);msReject(fn()=>T::remix(1,1,$template['id']),'Disabled template remixed');msReject(fn()=>T::detail(2,$template['id']),'Cross-tenant template accepted');T::delete(1,$template['id']);msCheck(is_file($dir.'/motion.mp4'),'Soft-delete removed shared bytes');
    // Simulate a confirmed, already stored provider receipt. Shared settlement and asset projection still run for real.
    $q=J::estimate(1,1,$args);$completed=J::create(1,1,$args+['quote_token'=>$q['token'],'idempotency_key'=>'ms_completed_'.bin2hex(random_bytes(12))]);$finished=J::owned(1,1,$completed['id']);
    $stored=\app\common\service\app\aigc_micro_video\MicroVideoMedia::validateVideo(1,1,$video);
    $result=array_replace($stored,['video_uri'=>$stored['uri'],'video_url'=>$stored['url']]);
    $settle=new ReflectionMethod(V::class,'settle');$settle->setAccessible(true);$settle->invoke(null,(int)$finished['consumption_id'],[$result],'fixture-request','fixture-upstream',['status'=>'completed']);
    $consumption=Db::name('ai_consumption_log')->where('id',$finished['consumption_id'])->find();msCheck($consumption['billing_status']==='settled','Successful receipt was delivered before settlement');
    J::syncConsumption($consumption,(int)$finished['id']);J::syncConsumption($consumption,(int)$finished['id']);$success=J::owned(1,1,$finished['id']);
    msCheck($success['status']==='success'&&S::table('result')->where('task_id',$finished['id'])->count()===1,'Managed result projection was not idempotent');
    $results=J::dto($success)['videos'];$ref='motion_sync:result:'.$results[0]['id']; // Prove the shared source can be resolved without any private provider data.
    $page=\app\common\service\WorkspaceMediaPickerService::page(1,1,['tab'=>'assets','types'=>'video','search'=>'保持角色外观']);
    msCheck(in_array($ref,array_column($page['lists'],'id'),true),'Completed result missing from shared asset picker');
    S::save(1,1,['id'=>$project['id'],'revision'=>$project['revision'],'body'=>array_replace($body,['prompt'=>'后来修改的草稿'])]);
    $published=T::fromJob(1,(int)$success['id'],['title'=>'完成记录转模板']);msCheck($published['configuration']['prompt']===$body['prompt'],'Template used the mutable draft instead of the submitted snapshot');
    J::delete(1,1,(int)$success['id']);msCheck(T::detail(1,$published['id'])['id']===$published['id']&&is_file($dir.'/motion.mp4'),'History deletion damaged a published template');
    echo "PASS measured media, native model scope, drafts, quote/idempotency, durable queue, freeze/refund, unknown retention, tenant isolation, templates, complete remix, settled success, shared assets and submitted snapshots (no provider calls)\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
