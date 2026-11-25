<?php
// Local market/worker integration. No upstream generation or debit. All fixture rows roll back.
use app\common\service\app\aigc_product_video\{ProductVideoPolicy as Policy,ProductVideoService as Projects,ProductVideoJobs as Jobs,VideoReplicationWorkflow as Workflow};
use app\common\service\ai\StudioMediaInputService;
use app\common\service\MediaBinaryService;
use think\facade\Db;

require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
if (getenv('REPLICATION_LOCAL_DB') !== 'x_cn' || config('database.connections.mysql.database') !== 'x_cn') {
    throw new RuntimeException('Local test database required');
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $action): void {
    try { $action(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected the request to be rejected');
}
$dir = public_path().'uploads/replication_test_'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
Db::startTrans();
try {
    $seed = 'uploads/'.basename($dir).'/';
    file_put_contents($dir.'/product.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $proc = proc_open([MediaBinaryService::resolve('ffmpeg'),'-v','error','-f','lavfi','-i','color=blue:s=96x64:d=9','-pix_fmt','yuv420p',$dir.'/sample.mp4'],[], $pipes);
    check(proc_close($proc)===0,'Video fixture creation failed');
    foreach (['product.png'=>10,'sample.mp4'=>20] as $name=>$type) {
        Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>$type,'name'=>$name,'uri'=>$seed.$name,
            'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    }
    Policy::forReplication(static function() use ($seed,$dir) {
        $models = Projects::models(1,'video')['models'];
        $id = $models[0]['id'];
        $spec = Projects::models(1,'video',$id)['models'][0]['specs'][0];
        $body = ['video_selection'=>['model_id'=>$id]+$spec, 'reference_video'=>['uri'=>$seed.'sample.mp4'],
            'clip'=>['start'=>2,'end'=>7],'images'=>[['uri'=>$seed.'product.png']]];
        rejects(fn()=>Projects::save(1,1,['body'=>array_replace($body,['clip'=>['start'=>7,'end'=>12]])]));
        $project = Projects::save(1,1,['body'=>$body]);
        rejects(fn()=>Projects::owned(1,2,$project['id']));
        rejects(fn()=>Projects::owned(2,1,$project['id']));
        $params=['project_id'=>$project['id'],'revision'=>$project['revision'],'action'=>'video'];
        $quote=Jobs::estimate(1,1,$params);
        check(count($quote['phased_costs'])===2,'Automatic generation requires both stages');
        $input=$params+['quote_token'=>$quote['quote_token'],'idempotency_key'=>bin2hex(random_bytes(16))];
        $job=Jobs::create(1,1,$input);
        check(Jobs::create(1,1,$input)['id']===$job['id'],'Retry created a duplicate job');
        $root=Jobs::owned(1,1,$job['id']);
        $request=Policy::json($root['request_json']);
        $asset=$request['script_request']['video'];
        $clip=StudioMediaInputService::videoClipInput($asset);
        file_put_contents($dir.'/clip.mp4',base64_decode(explode(',',$clip,2)[1],true));
        $probe=proc_open([MediaBinaryService::resolve('ffprobe'),'-v','error','-show_entries','format=duration','-of','csv=p=0',$dir.'/clip.mp4'],[1=>['pipe','w']],$pipes);
        $seconds=(float)stream_get_contents($pipes[1]);fclose($pipes[1]);
        check(proc_close($probe)===0 && abs($seconds-5)<0.25,'Analysis received the wrong segment');
        check(count($request['script_request']['images'])===1 && array_column($request['reference_assets'],'type')===['image'],'Wrong assets sent to model');
        Workflow::advance($root,$request);
        Workflow::advance($root,$request);
        $children=Projects::table('job_stage')->where('root_job_id',$root['id'])->column('child_job_id');
        check(count($children)===1,'Duplicate analysis child');
        Jobs::table()->where('id',$children[0])->update(['status'=>'success','billing_status'=>'settled','user_charge_points'=>2,
            'result_json'=>Policy::encode(['summary'=>'测试脚本','shots'=>[['start'=>0,'end'=>2,'description'=>'商品全景'],['start'=>2,'end'=>$spec['duration'],'description'=>'演示细节']]])]);
        Workflow::advance($root,$request);
        $staged=Policy::json(Jobs::owned(1,1,$job['id'])['request_json']);
        check(!isset($staged['script_request']) && str_contains($staged['prompt'],'演示细节'),'Generated script did not reach the render job');
        $body['prompt']='自己编辑的分镜';$body['prompt_review_images']=[$seed.'product.png'];
        $edited=Projects::save(1,1,['body'=>$body]);
        $manual=Jobs::prepare(1,1,['project_id'=>$edited['id'],'revision'=>$edited['revision'],'action'=>'video']);
        check(!isset($manual['request']['script_request']) && str_contains($manual['request']['prompt'],$body['prompt']),'Edited script triggered a second analysis');
    });
    check(Policy::app()===Policy::APP,'Application context was not restored');
    echo "PASS video replication segment, ownership, quote, idempotency, pipeline and edited script\n";
} finally {
    Db::rollback();
    foreach(glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
