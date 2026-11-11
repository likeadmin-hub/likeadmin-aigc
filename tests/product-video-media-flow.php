<?php
// Local generated fixture, transaction rollback, no paid provider submission.
require dirname(__DIR__).'/vendor/autoload.php';$app=new think\App();$app->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
use app\common\service\app\aigc_product_video\{ProductVideoService as S,ProductVideoJobs as J,ProductVideoPolicy as P};
use app\common\service\ai\StudioMediaInputService as M;
use think\facade\Db;
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local opt-in required');
function pvmAssert(bool $ok,string $why){if(!$ok)throw new RuntimeException($why);}
$dir=public_path().'uploads/pv_media_'.bin2hex(random_bytes(8));mkdir($dir,0700,true);Db::startTrans();
try{
    $process=proc_open(['/usr/bin/ffmpeg','-v','error','-f','lavfi','-i','color=c=white:s=64x64:d=1','-f','lavfi','-i','sine=frequency=440:duration=1','-c:v','libx264','-c:a','aac','-shortest',$dir.'/video.mp4'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);pvmAssert(proc_close($process)===0,'Fixture codec');
    file_put_contents($dir.'/image.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $uri='uploads/'.basename($dir).'/video.mp4';$image='uploads/'.basename($dir).'/image.png';
    foreach([[20,$uri],[30,$uri],[10,$image]] as [$type,$path])Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>$type,'name'=>'local fixture','uri'=>$path,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    pvmAssert(M::validateAudio(1,1,$uri)['duration']>0,'Actual audio verification');$encoded=M::videoInput(M::validateVideo(1,1,$uri));pvmAssert(str_starts_with($encoded,'data:video/')&&strlen($encoded)<10000000,'Qwen local video conversion');
    $models=S::config(1)['analysis_models'];$p=S::save(1,1,['body'=>['images'=>[['uri'=>$image]],'analysis_model_id'=>(string)$models[0]['id']]]);
    $root=J::table()->insertGetId(['tenant_id'=>1,'user_id'=>1,'project_id'=>$p['id'],'project_revision'=>$p['revision'],'action'=>'video','idempotency_key'=>'pv_media_'.bin2hex(random_bytes(16)),'input_hash'=>str_repeat('a',64),'status'=>'success','request_json'=>P::encode(['snapshot'=>$p['body']]),'receipt_json'=>'{}','result_json'=>P::encode(['videos'=>[['uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']]]),'create_time'=>time(),'update_time'=>time()]);
    $args=['project_id'=>$p['id'],'revision'=>$p['revision'],'action'=>'chapters','video_job_id'=>$root];$q=J::estimate(1,1,$args);$job=J::create(1,1,$args+['quote_token'=>$q['quote_token'],'idempotency_key'=>'pv_media_'.bin2hex(random_bytes(16))]);
    $dto=J::dto(J::owned(1,1,$root));pvmAssert($dto['result']['chapters']['status']==='queued','Manual analysis attaches to the original video card');
    pvmAssert(!in_array($job['id'],array_column(J::lists(1,1,[])['lists'],'id'),true),'Attached chapter job does not pollute helper history');
    $blocked=false;try{J::delete(1,1,$root);}catch(InvalidArgumentException){$blocked=true;}pvmAssert($blocked,'Active chapter job prevents deleting its video');
    J::table()->where('id',$job['id'])->update(['status'=>'success','billing_status'=>'settled','result_json'=>P::encode(['shots'=>[['start'=>0,'end'=>1,'description'=>'实际画面']]])]);
    pvmAssert(count(J::dto(J::owned(1,1,$root))['result']['chapters']['data']['shots'])===1,'Chapter result is visible in the original video preview');
    echo "PASS actual media validation, chapter attachment, history grouping and active retention\n";
}finally{Db::rollback();foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
