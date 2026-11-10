<?php
/** Local rollback acceptance; real shared billing, owned generated fixtures, no upstream submission. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();request()->setDomain('https://motion-fixture.example');
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
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
    echo "PASS measured media, native model scope, drafts, quote/idempotency, durable queue, freeze/refund, unknown retention, tenant isolation, templates and complete remix (no provider calls)\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
