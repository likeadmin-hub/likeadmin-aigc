<?php
// Local market/worker integration. No upstream generation or debit. All fixture rows roll back.
use app\common\service\app\aigc_product_video\{ProductVideoPolicy as Policy,ProductVideoService as Projects,ProductVideoJobs as Jobs,ProductVideoWorker as Worker,VideoReplicationWorkflow as Workflow};
use app\common\service\ai\StudioMediaInputService;
use app\common\service\MediaBinaryService;
use app\common\service\app\aigc_product_video\VideoReplicationTemplates as Templates;
use app\common\service\app\{StudioBuiltinTemplates as Builtins, StudioBuiltinCatalog as Catalog};
use app\common\service\storage\StorageConfigService;
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
// Exercise the real quote, reservation and worker recovery, stopping at the provider boundary.
final class LocalReplicationVideoRuntime extends \app\common\service\power\MarketVideoModelRuntimeService
{
    public static int $submissions = 0;

    public static function submit(int $consumptionId, array $request): array
    {
        self::$submissions++;
        return ['status' => 'running'];
    }
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
        $specs = Projects::models(1,'video',$id)['models'][0]['specs'];
        $spec = array_values(array_filter($specs, static fn($s) => (int)$s['duration'] === 10))[0] ?? null;
        check($spec !== null, 'Fixture needs a ten-second render option');
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
        $planRequest = $request['script_request'];
        $content = Policy::json($planRequest['content']);
        check($planRequest['duration'] === 5 && $content['duration'] === 5 && $content['actual_duration'] === 5, 'Automatic analysis must use the selected five seconds');
        check((int)$request['selection']['duration'] === 10, 'Analysis changed the selected render duration');
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
        $reply = ['summary'=>'测试脚本','shots'=>[
            ['start'=>0,'end'=>2.5,'description'=>'商品全景'],
            ['start'=>2.5,'end'=>5,'description'=>'演示细节']
        ]];
        $child = Jobs::owned(1,1,$children[0]);
        $script = Worker::parse($child, ['content'=>Policy::encode($reply)]);
        $wrong = $reply;
        $wrong['shots'][1]['end'] = 10;
        rejects(fn()=>Worker::parse($child, ['content'=>Policy::encode($wrong)]));
        // A previously issued ten-second analysis still validates against its own saved request.
        $legacy = array_replace($child, ['request_json'=>Policy::encode(array_replace($planRequest, ['duration'=>10]))]);
        check(Worker::parse($legacy,['content'=>Policy::encode($wrong)])['duration'] === 10, 'Legacy plan timing changed');
        Jobs::table()->where('id',$children[0])->update(['status'=>'success','billing_status'=>'settled','user_charge_points'=>2,
            'result_json'=>Policy::encode($script)]);
        Workflow::advance($root,$request);
        $staged=Policy::json(Jobs::owned(1,1,$job['id'])['request_json']);
        check(!isset($staged['script_request']) && str_contains($staged['prompt'],'演示细节') && str_contains($staged['prompt'],'参考视频')
            && str_contains($staged['prompt'],'10 秒成片') && $staged['reference_assets'][1]['uri'] === $render['uri'],'Generated script, render duration or original crop did not reach the render job');
        $staged['runtime'] = LocalReplicationVideoRuntime::class;
        Worker::write($job['id'], ['request_json'=>Policy::encode($staged)]);
        Worker::tick($job['id']);
        $renderJob = Jobs::owned(1,1,$job['id']);
        check(LocalReplicationVideoRuntime::$submissions === 1, 'Quoted render was blocked before provider submission: '.$renderJob['error']);
        $consumptionId = (int)$renderJob['consumption_id'];
        $reservation = Db::name('ai_consumption_log')->where('id',$consumptionId)->findOrFail();
        check((int)$reservation['sku_id'] === (int)$staged['quote']['market_sku_id'], 'Reservation lost the video-input SKU');
        check((float)$reservation['reserved_user_price'] === (float)$staged['quote']['user_charge_points'], 'Frozen amount differs from the render quote');

        // A pre-existing mismatched reservation must be released before the worker retries submission.
        Db::name('ai_consumption_log')->where('id',$consumptionId)->update(['sku_id'=>(int)$reservation['sku_id']+1]);
        Worker::write($job['id'], ['next_run_at'=>time()]);
        Worker::tick($job['id']);
        Worker::tick($job['id']);
        $failed = Db::name('ai_consumption_log')->where('id',$consumptionId)->findOrFail();
        check($failed['billing_status'] === 'refunded' && $failed['error_code'] === 'reservation_sku_mismatch', 'Recovered mismatched SKU was not refunded');
        check(LocalReplicationVideoRuntime::$submissions === 1, 'Mismatched reservation reached the provider');
        check(Db::name('ai_consumption_event')->where(['consumption_id'=>$consumptionId,'event_type'=>'refund'])->count() === 1, 'Recovery did not refund exactly once');
        $body['prompt']='自己编辑的分镜';$body['prompt_review_images']=[$seed.'product.png'];
        $body['requirements']='保留产品颜色';
        $edited=Projects::save(1,1,['body'=>$body]);
        $testProjects[] = $edited['id'];
        $manual=Jobs::prepare(1,1,['project_id'=>$edited['id'],'revision'=>$edited['revision'],'action'=>'video']);
        check(!isset($manual['request']['script_request']) && str_contains($manual['request']['prompt'],$body['prompt']),'Edited script triggered a second analysis');
        check(str_contains($manual['request']['prompt'],$body['requirements']),'Custom direction missing from the render prompt');
        // A manual analysis of a fractional interval must round-trip into the editable draft.
        $fraction = Projects::save(1,1,['body'=>array_replace($body,['clip'=>['start'=>2,'end'=>7.5]])]);
        $planParams = ['project_id'=>$fraction['id'],'revision'=>$fraction['revision'],'action'=>'plan'];
        $planQuote = Jobs::estimate(1,1,$planParams);
        $planJob = Jobs::create(1,1,$planParams+['quote_token'=>$planQuote['quote_token'],'idempotency_key'=>bin2hex(random_bytes(16))]);
        $planRow = Jobs::owned(1,1,$planJob['id']);
        $manualRequest = Policy::json($planRow['request_json']);
        check(Policy::json($manualRequest['content'])['duration'] === 5.5, 'Manual analysis truncated the clip duration');
        $reply['shots'][1]['end'] = 5.5;
        $fractionScript = Worker::parse($planRow,['content'=>Policy::encode($reply)]);
        Jobs::table()->where('id',$planJob['id'])->update(['status'=>'success','result_json'=>Policy::encode($fractionScript)]);
        $adopted = Projects::adopt(1,1,['job_id'=>$planJob['id'],'revision'=>$fraction['revision']])['body'];
        check(str_contains($adopted['prompt'],'2.5-5.5秒') && (int)$adopted['video_selection']['duration'] === 10, 'Applying analysis changed or rejected fractional timing');
        // Save the successful render as a template, then grant an editable copy of its original inputs.
        $snapshot = Policy::json(Projects::owned(1,1,$edited['id'])['body_json']);
        $previewFile = Db::name('tenant_file')->where(['tenant_id'=>1,'uri'=>$seed.'sample.mp4'])->findOrFail();
        $previewFile['video_uri'] = $previewFile['uri'];
        Jobs::table()->where('id',$job['id'])->update(['status'=>'success','request_json'=>Policy::encode(['snapshot'=>$snapshot]),'result_json'=>Policy::encode(['videos'=>[$previewFile]])]);
        $case = Templates::fromJob(1,$job['id'],[]);
        check(Templates::fromJob(1,$job['id'],[])['id']===$case['id'],'Repeated job conversion duplicated a template');
        rejects(fn()=>Templates::fromJob(1,$children[0],[]));
        rejects(fn()=>Templates::detail(2,$case['id']));
        $copy = Templates::remix(1,1,$case['id'])['body'];
        check($copy['reference_video']['uri']===$seed.'sample.mp4' && $copy['clip']===$snapshot['clip'] && $copy['prompt']===$body['prompt'],'Remix lost source clip or script');
        rejects(fn()=>Builtins::publish(Templates::APP,$case['id'],['confirm_public_media'=>true],1));

        // Use isolated public object receipts to exercise the exact publishing path without an upload.
        $config=StorageConfigService::getEffectiveConfig(1);$engine=$config['default'];
        $coords=['storage_engine'=>$engine,'storage_scope'=>$config['scope'],'storage_domain'=>$config['engine'][$engine]['domain']];
        foreach (['reference_video','images'] as $key) {
            if ($key==='images') $snapshot[$key][0]=array_replace($snapshot[$key][0],$coords);
            else $snapshot[$key]=array_replace($snapshot[$key],$coords);
        }
        Db::name('tenant_file')->where('tenant_id',1)->whereIn('uri',[$seed.'sample.mp4',$seed.'product.png'])->update($coords);
        Jobs::table()->where('id',$job['id'])->update(['request_json'=>Policy::encode(['snapshot'=>$snapshot]),'result_json'=>Policy::encode(['videos'=>[array_replace($previewFile,$coords)]])]);
        Catalog::withDirectory(Templates::APP,$dir,static function() use ($job,$seed,$coords,$snapshot) {
            $app=Templates::APP;
            rejects(fn()=>Builtins::fromJob($app,$job['id'],[],1));
            rejects(fn()=>Builtins::fromJob($app,$job['id'],['confirm_public_media'=>true],2));
            $published=Builtins::fromJob($app,$job['id'],['confirm_public_media'=>true],1);
            $id=$published['id'];
            check($published['is_builtin'] && $published['builtin_version']===1,'Job was not published as a builtin');
            check(Builtins::fromJob($app,$job['id'],['confirm_public_media'=>true],1)['id']===$id,'Duplicate builtin');
            $source=(int)Builtins::info($app,$id)['source_case_id'];
            rejects(fn()=>Builtins::publish($app,$source,['confirm_public_media'=>true,'expected_version'=>0],1));
            check(Builtins::publish($app,$source,['confirm_public_media'=>true,'expected_version'=>1],1)['builtin_version']===2,'Source template update failed');
            $definition=Builtins::definition($app,$id);$b=$definition['configuration'];
            check(!isset($b['requirements'],$b['render_clip_uri'],$b['reference_video']['file_id'],$b['reference_video']['url'],$b['images'][0]['user_id']) && $b['video_selection']['model_id']==='', 'Builtin contains installation-specific data');
            check($b['clip']==$snapshot['clip'] && $b['reference_video']['uri']===$seed.'sample.mp4','Published the rendered clip instead of the original reference');
            check(Catalog::mediaUrl($b['reference_video']['uri'],$coords['storage_engine'],$coords['storage_domain'])!==null,'Reference video was not pinned');
            check(Templates::detail(2,$id)['readonly'],'Other tenants cannot see the shared template');
            rejects(fn()=>Templates::change(1,['id'=>$id,'status'=>0],'status'));
        });
        Db::name('tenant_file')->where('tenant_id',1)->whereIn('uri',[$seed.'sample.mp4',$seed.'product.png'])->update(['storage_engine'=>'local','storage_scope'=>'tenant','storage_domain'=>'']);
        $fallback = Projects::models(1,'video',$imageOnly[0]['id'])['models'][0];
        $body['video_selection'] = ['model_id'=>$fallback['id']]+$fallback['specs'][0];
        $edited = Projects::save(1,1,['body'=>$body]);
        $imageJob = Jobs::prepare(1,1,['project_id'=>$edited['id'],'revision'=>$edited['revision'],'action'=>'video']);
        check(array_column($imageJob['request']['reference_assets'],'type') === ['image'], 'Script-only model received a video');
    });
    check(Policy::app()===Policy::APP,'Application context was not restored');
    echo "PASS video replication exact clip, native inputs, estimates, retries, analysis, script-only fallback, template publication and remix\n";
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
