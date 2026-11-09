<?php
// Explicit local-only integration test. All fixture rows roll back; no provider is called.
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App();$app->initialize();
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
    $b=P::body(['images'=>[['uri'=>$uri]],'prompt'=>'展示外观','video_selection'=>['model_id'=>$m['id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio'],'duration'=>$spec['duration']]]);
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
    echo "PASS local ownership, optimistic drafts, market quote, idempotency and queue isolation\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
