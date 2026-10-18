<?php
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasMediaPreviewService as Preview,CanvasTemplateMedia as Media};
use app\common\model\app\aigc_canvas\AigcCanvasRun;
use think\facade\Db;
$n=0;function check($ok,$why){global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli' && $db['hostname']==='127.0.0.1' && in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try {
    $tenant=1999999857;$user=$tenant;request()->tenantId=$tenant;
    $p=Canvas::createProject($tenant,$user,['name'=>'preview fixture','nodes'=>[
        ['id'=>'video','type'=>'video','metadata'=>['url'=>'https://cdn.example.com/old.mp4','duration'=>5,'prompt'=>'Changed prompt','quality'=>'2K','referenceImages'=>[]]],
        ['id'=>'image','type'=>'image','metadata'=>['mediaItems'=>[['id'=>'old','type'=>'image','url'=>'https://cdn.example.com/old.png'],['id'=>'new','type'=>'image','url'=>'https://cdn.example.com/new.png']],'activeMediaId'=>'new','prompt'=>'Changed image prompt']],
        ['id'=>'upload','type'=>'image','metadata'=>['image'=>'https://cdn.example.com/upload.png','prompt'=>'Model defaults must not be displayed','quality'=>'1K']]
    ],'edges'=>[]]);
    foreach (['video','image'] as $type) foreach (['old','new'] as $age) {
        $url='https://cdn.example.com/'.$age.($type==='video'?'.mp4':'.png');
        AigcCanvasRun::create(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$p['id'],'node_id'=>$type,'run_type'=>$type,'status'=>'success','source_task_id'=>0,
            'params_json'=>['prompt'=>$age.' submitted '.$type,'submitted_model_label'=>'Actual model','ratio'=>'9:16','resolution'=>'768P','duration'=>$age==='old'?7:10,'quantity'=>2,'reference_assets'=>[['url'=>'https://cdn.example.com/ref.png','type'=>'image','name'=>'产品']],'reference_images'=>['https://cdn.example.com/ref.png']],
            'result_json'=>['results'=>[['url'=>$url]]],'create_time'=>time(),'delete_time'=>0]);
    }
    $v=Preview::detail($tenant,$user,['project_id'=>$p['id'],'node_id'=>'video','url'=>'https://cdn.example.com/old.mp4?signature=fresh']);
    check($v['prompt']==='old submitted video','Editable prompt replaced the submitted snapshot');
    check(array_column($v['parameters'],'value','label')['时长']==='7秒','Old output displayed latest duration');
    check(count($v['references'])===1,'Duplicate reference displayed');
    $immediate=Preview::detail($tenant,$user,['project_id'=>$p['id'],'node_id'=>'video','url'=>'https://cdn.example.com/new.mp4']);
    check($immediate['prompt']==='new submitted video','Preview before output autosave lost its matched generation');
    foreach (['old','new'] as $age) {
        $d=Preview::detail($tenant,$user,['project_id'=>$p['id'],'node_id'=>'image','url'=>'https://cdn.example.com/'.$age.'.png']);
        check($d['prompt']===$age.' submitted image','Collection results shared the latest snapshot');
    }
    check(Preview::detail($tenant,$user,['project_id'=>$p['id'],'node_id'=>'upload','url'=>'https://cdn.example.com/upload.png'])===[],'Upload invented a generation configuration');
    check(Preview::detail($tenant,$user,['project_id'=>$p['id'],'node_id'=>'video','url'=>'https://foreign.example.com/old.mp4'])===[],'Foreign output matched by filename only');
    foreach ([[$tenant+1,$user],[$tenant,$user+1]] as [$t,$u]) {
        try { Preview::detail($t,$u,['project_id'=>$p['id'],'node_id'=>'video','url'=>'https://cdn.example.com/old.mp4']);throw new RuntimeException('Foreign owner accepted'); }
        catch(InvalidArgumentException $e){check(true,'isolated');}
    }
    $v['provider_payload']=['api_key'=>'NEVER_COPY'];$v['task_id']=99;
    $copy=static fn($url,$type,$row)=>['url'=>'https://public.example.com/'.basename($url),'file'=>['uri'=>'retained/'.basename($url),'checksum'=>str_repeat('a',64)]];
    $m=Media::capture('video',['mediaItems'=>[['id'=>'a','type'=>'video','url'=>'https://cdn.example.com/old.mp4','generation'=>$v]]],$copy);
    check($m['mediaItems'][0]['generation']['prompt']===$v['prompt'],'Template lost generation snapshot');
    check(!str_contains(json_encode($m),'NEVER_COPY') && !str_contains(json_encode($m),'task_id'),'Template leaked source/provider identity');
    $h=Media::hydrate($m,static fn($file)=>'https://fresh.example.com/'.$file['uri']);
    check($h['mediaItems'][0]['generation']['references'][0]['url']==='https://fresh.example.com/retained/ref.png','Snapshot reference was not independently retained/hydrated');
    check(!Preview::sameMedia('https://a.example.com/a.mp4','https://b.example.com/a.mp4'),'Different hosts matched');
    $g=Preview::fromRun(['run_type'=>'image','params_json'=>['resolution'=>'2k','prompt'=>'Actual prompt'],'create_time'=>1792299010]);
    check(array_column($g['parameters'],'value','label')['分辨率']==='2K','Resolution units differ from creation-page display');
    $presets=\app\common\service\app\aigc_canvas\CanvasBuiltinPresetService::all();
    foreach ($presets as $preset) foreach ($preset['graph']['nodes'] as $node) foreach ($node['metadata']['mediaItems'] ?? [] as $media) {
        if (empty($media['generation'])) continue;
        $instantiated=\app\common\service\app\aigc_canvas\CanvasTemplateService::instantiate($preset['graph']);
        check(count(array_filter($instantiated['nodes'],static fn($n)=>!empty($n['metadata']['mediaItems'][0]['generation'])))>0,'Builtin use stripped public generation details');
        $owned=Canvas::createProject($tenant,$user,['name'=>'old preset preview fixture','nodes'=>[['id'=>'remapped','type'=>$node['type'],'metadata'=>['url'=>$media['url']]]],'edges'=>[]]);
        $info=Preview::detail($tenant,$user,['project_id'=>$owned['id'],'node_id'=>'remapped','url'=>$media['url']]);
        check($info['prompt']===$media['generation']['prompt'],'Old remapped template cannot recover its catalog snapshot');
    }
    echo "PASS $n output binding, historical settings, collection selection, template retention and owner isolation checks; database rolled back\n";
} finally { Db::rollback(); }
