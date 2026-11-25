<?php
// Local market/worker integration. No upstream generation or debit. All fixture rows roll back.
use app\common\service\app\aigc_product_video\{ProductVideoPolicy as Policy,ProductVideoService as Projects,ProductVideoJobs as Jobs,VideoReplicationWorkflow as Workflow};
use app\common\service\ai\StudioMediaInputService;
use app\common\service\MediaBinaryService;
use app\common\service\power\{MarketGenerationMediaInputs,MarketVideoRuntimeService};
use think\facade\Db;

$_SERVER['HTTP_HOST'] ??= 'localhost';
require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
if (getenv('REPLICATION_LOCAL_DB') !== 'x_cn' || config('database.connections.mysql.database') !== 'x_cn') {
    throw new RuntimeException('Local test database required');
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $action): void {
    try { $action(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected the request to be rejected');
}
$dir = public_path().'uploads/replication_test_'.bin2hex(random_bytes(6));
$testProjects = [];
mkdir($dir,0700,true);
Db::startTrans();
try {
    $seed = 'uploads/'.basename($dir).'/';
    file_put_contents($dir.'/product.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $proc = proc_open([MediaBinaryService::resolve('ffmpeg'),'-v','error','-f','lavfi','-i','color=red:s=96x64:d=2:r=30000/1001',
        '-f','lavfi','-i','color=blue:s=96x64:d=7:r=30000/1001','-f','lavfi','-i','sine=frequency=440:duration=9',
        '-filter_complex','[0:v][1:v]concat=n=2:v=1:a=0[v]','-map','[v]','-map','2:a','-pix_fmt','yuv420p',$dir.'/sample.mp4'],[], $pipes);
    check(proc_close($proc)===0,'Video fixture creation failed');
    foreach (['product.png'=>10,'sample.mp4'=>20] as $name=>$type) {
        Db::name('tenant_file')->insert(['tenant_id'=>1,'source_id'=>1,'source'=>1,'type'=>$type,'name'=>$name,'uri'=>$seed.$name,
            'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
    }
    Policy::forReplication(static function() use ($seed,$dir,&$testProjects) {
        $models = Projects::models(1,'video')['models'];
        $direct = array_values(array_filter($models, fn($m) => !empty($m['replication_reference_modes'])));
        $imageOnly = array_values(array_filter($models, fn($m) => empty($m['replication_reference_modes'])));
        check($direct && $imageOnly, 'Fixture needs both video-reference and script-only models');
        $mini = array_values(array_filter($direct, fn($m) => stripos($m['label'], 'Seedance 2.0 Mini') !== false));
        $id = ($mini[0] ?? $direct[0])['id'];
        $spec = Projects::models(1,'video',$id)['models'][0]['specs'][0];
        $body = ['video_selection'=>['model_id'=>$id]+$spec, 'reference_video'=>['uri'=>$seed.'sample.mp4'],
            'clip'=>['start'=>2,'end'=>7],'images'=>[['uri'=>$seed.'product.png']]];
        rejects(fn()=>Projects::save(1,1,['body'=>array_replace($body,['clip'=>['start'=>7,'end'=>12]])]));
        $project = Projects::save(1,1,['body'=>$body]);
        $testProjects[] = $project['id'];
        rejects(fn()=>Projects::owned(1,2,$project['id']));
        rejects(fn()=>Projects::owned(2,1,$project['id']));
        $params=['project_id'=>$project['id'],'revision'=>$project['revision'],'action'=>'video'];
        $preview = Jobs::estimate(1,1,['action'=>'video','preview'=>1,'body'=>$body]);
        check(!isset($preview['quote_token']) && !isset(Policy::json(Projects::owned(1,1,$project['id'])['body_json'])['render_clip_uri']), 'Preview wrote a clip or issued a spendable quote');
        $quote=Jobs::estimate(1,1,$params);
        check((float)$preview['user_charge_points'] === (float)$quote['user_charge_points'], 'Preview and saved-clip prices differ');
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
        check(count($request['script_request']['images']) === 1 && array_column($request['reference_assets'],'type') === ['image','video'], 'Render requires a product image and a video');
        [$image,$render] = Workflow::refreshRenderAssets(1,1,$request['reference_assets']);
        check($render['uri'] !== $seed.'sample.mp4' && abs($render['duration']-5)<0.001 && $render['has_audio'], 'Render must use the selected segment with its audio and exact length');
        check($request['reference_images'] === [$image['uri']], 'Clip leaked into image inputs');
        $decoded = proc_open([MediaBinaryService::resolve('ffmpeg'),'-v','error','-i',public_path().$render['uri'],'-frames:v','1',
            '-vf','scale=1:1','-pix_fmt','rgb24','-f','rawvideo','pipe:1'], [1=>['pipe','w']], $pipes);
        $rgb = stream_get_contents($pipes[1]); fclose($pipes[1]);
        check(proc_close($decoded)===0 && strlen($rgb)===3 && ord($rgb[2])>200 && ord($rgb[0])<50, 'Crop starts at the wrong frame');
        $native = Workflow::withClipDuration([$render], fn() => MarketVideoRuntimeService::prepareRequest(
            $request['quote']['market_snapshot'], $request['selection']+array_intersect_key($request,array_flip(['prompt','reference_assets','reference_images'])))->nativeInput);
        $sent = MarketGenerationMediaInputs::extract($native);
        check($sent['video'] === [$render['url']] && $sent['image'] === [$image['url']], 'Native provider payload lost the exact clip or product image');
        Workflow::advance($root,$request);
        Workflow::advance($root,$request);
        $children=Projects::table('job_stage')->where('root_job_id',$root['id'])->column('child_job_id');
        check(count($children)===1,'Duplicate analysis child');
        $duration = (int)$spec['duration'];
        $script = Policy::plan(['summary'=>'测试脚本','shots'=>[
            ['start'=>0,'end'=>$duration/2,'description'=>'商品全景'],
            ['start'=>$duration/2,'end'=>$duration,'description'=>'演示细节']
        ]], $duration);
        Jobs::table()->where('id',$children[0])->update(['status'=>'success','billing_status'=>'settled','user_charge_points'=>2,
            'result_json'=>Policy::encode($script)]);
        Workflow::advance($root,$request);
        $staged=Policy::json(Jobs::owned(1,1,$job['id'])['request_json']);
        check(!isset($staged['script_request']) && str_contains($staged['prompt'],'演示细节') && str_contains($staged['prompt'],'参考视频')
            && $staged['reference_assets'][1]['uri'] === $render['uri'],'Generated script or original crop did not reach the render job');
        $body['prompt']='自己编辑的分镜';$body['prompt_review_images']=[$seed.'product.png'];
        $body['requirements']='保留产品颜色';
        $edited=Projects::save(1,1,['body'=>$body]);
        $testProjects[] = $edited['id'];
        $manual=Jobs::prepare(1,1,['project_id'=>$edited['id'],'revision'=>$edited['revision'],'action'=>'video']);
        check(!isset($manual['request']['script_request']) && str_contains($manual['request']['prompt'],$body['prompt']),'Edited script triggered a second analysis');
        check(str_contains($manual['request']['prompt'],$body['requirements']),'Custom direction missing from the render prompt');
        $fallback = Projects::models(1,'video',$imageOnly[0]['id'])['models'][0];
        $body['video_selection'] = ['model_id'=>$fallback['id']]+$fallback['specs'][0];
        $edited = Projects::save(1,1,['body'=>$body]);
        $imageJob = Jobs::prepare(1,1,['project_id'=>$edited['id'],'revision'=>$edited['revision'],'action'=>'video']);
        check(array_column($imageJob['request']['reference_assets'],'type') === ['image'], 'Script-only model received a video');
    });
    check(Policy::app()===Policy::APP,'Application context was not restored');
    echo "PASS video replication exact clip, native inputs, estimates, retries, analysis and script-only fallback\n";
} finally {
    Policy::forReplication(static function () use ($testProjects) {
        foreach ($testProjects as $projectId) {
            $body = Policy::json(Projects::owned(1,1,$projectId)['body_json']);
            $uri = $body['render_clip_uri'] ?? '';
            if (str_starts_with($uri, 'uploads/aigc_video/') && is_file(public_path().$uri)) unlink(public_path().$uri);
        }
    });
    Db::rollback();
    foreach(glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
