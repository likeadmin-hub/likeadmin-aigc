<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;

// JSON-lines bridge to real HTTP inside the internal network. No host port,
// business credentials, arbitrary URLs, provider routes or user profile.
$inserted=[];$canvasId=0;$process=null;
$mockGeneration=($argv[1]??'')==='mock-generation';
try {
    if (Db::name('tenant')->where('id',94011)->count() || Db::name('user')->where('id',95011)->count() || Db::name('app')->whereIn('code',['aigc_short_drama','aigc_canvas'])->count()) throw new RuntimeException('Browser fixture scope is not empty');
    foreach ([
        ['tenant',['id'=>94011,'sn'=>'browser-fixture','name'=>'Isolated browser','create_time'=>time(),'delete_time'=>null]],
        ['user',['id'=>95011,'sn'=>95011,'account'=>'browser-fixture','tenant_id'=>94011]],
        ['user_session',['tenant_id'=>94011,'user_id'=>95011,'token'=>'isolated-browser-http','terminal'=>4,'expire_time'=>time()+3600]],
        ['app',['code'=>'aigc_short_drama','status'=>'installed']],
        ['app',['code'=>'aigc_canvas','status'=>'disabled']],
        ['tenant_app',['tenant_id'=>94011,'app_code'=>'aigc_canvas','buy_status'=>'paid','enable_status'=>'disabled','shelf_status'=>'on','expire_time'=>time()+3600]],
        ['tenant_app',['tenant_id'=>94011,'app_code'=>'aigc_short_drama','buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+3600]],
        ['aigc_short_drama_config',['tenant_id'=>94011,'config_json'=>'{"canvas_agent":{"enabled":false}}','status'=>1]],
    ] as [$table,$row]) $inserted[]=[$table,Db::name($table)->insertGetId($row)];
    if ($mockGeneration) {
        Db::name('tenant')->where('id',94011)->update(['point_balance'=>100]);
        Db::name('user')->where('id',95011)->update(['user_money'=>100]);
    }
    $canvasId=Canvas::create(94011,95011,['title'=>'HTTP browser fixture'])['id'];
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:19080','-t',app()->getRootPath().'public',__DIR__.($mockGeneration?'/browser_generation_router.php':'/http_router.php')],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP fixture');
    $ready=false;
    for ($i=0;$i<50;$i++) {
        $socket=@fsockopen('127.0.0.1',19080,$errno,$error,0.1);
        if ($socket) {fclose($socket);$ready=true;break;}
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('HTTP fixture not ready');
    echo json_encode(['ready'=>true,'canvas_id'=>$canvasId,'tenant_id'=>94011]),PHP_EOL;
    while (($line=fgets(STDIN))!==false) {
        $request=json_decode($line,true,512,JSON_THROW_ON_ERROR);
        if (($request['action']??'')==='close') break;
        $action=$request['action']??'';$method=$request['method']??'GET';$body=$request['body']??[];
        if ($mockGeneration && $action==='evidence') {
            $scope=['tenant_id'=>94011,'user_id'=>95011];
            echo json_encode(['result'=>[
                'received'=>Db::name('aigc_short_drama_test_provider_receipt')->where($scope)->count(),
                'runs'=>Db::name('aigc_short_drama_canvas_run')->where($scope+['canvas_id'=>$canvasId])->count(),
                'tenant_balance'=>Db::name('tenant')->where('id',94011)->value('point_balance'),
                'user_balance'=>Db::name('user')->where('id',95011)->value('user_money'),
                'tenant_charges'=>Db::name('tenant_point_log')->where('tenant_id',94011)->count(),
                'user_charges'=>Db::name('user_account_log')->where('user_id',95011)->count(),
            ]]),PHP_EOL;
            continue;
        }
        $allowed=in_array($action,['current','save'],true) && (int)($body['id']??0)===$canvasId;
        if (in_array($action,['assets','independentCanvas'],true)) $allowed=$method==='GET';
        if ($mockGeneration && $action==='run') $allowed=(int)($body['canvas_id']??0)===$canvasId;
        if ($mockGeneration && $action==='task') $allowed=Db::name('aigc_short_drama_canvas_run')->where(['id'=>(int)($body['id']??0),'canvas_id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011])->count()===1;
        if (!$allowed || !in_array($method,['GET','POST'],true)) throw new RuntimeException('Request outside isolated browser fixture');
        $path=match ($action) {
            'assets'=>'app.aigc_short_drama.asset/lists',
            'independentCanvas'=>'app.aigc_canvas.project/lists',
            default=>'app.aigc_short_drama.canvas/'.$action,
        };
        // Read-only resource probes cannot invoke project material repair or
        // select a caller-controlled tenant/URL through query parameters.
        if (in_array($action,['assets','independentCanvas'],true)) $body=['page_size'=>100];
        $url='http://127.0.0.1:19080/api/'.$path.'?tenant_id=94011';
        if ($method==='GET') $url.='&'.http_build_query($body);
        $context=stream_context_create(['http'=>['method'=>$method,'timeout'=>10,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\ntoken: isolated-browser-http\r\n",
            'content'=>$method==='POST'?json_encode($body):'',
        ]]);
        $result=json_decode((string)file_get_contents($url,false,$context),true,512,JSON_THROW_ON_ERROR);
        echo json_encode(['result'=>$result]),PHP_EOL;
    }
} finally {
    if (is_resource($process)) {proc_terminate($process);foreach ($pipes as $pipe) fclose($pipe);proc_close($process);}
    if ($mockGeneration && $canvasId) {
        foreach (['aigc_short_drama_test_provider_receipt','aigc_short_drama_canvas_run','aigc_short_drama_generation_task','aigc_short_drama_asset','aigc_short_drama_canvas_poster_job','aigc_image_result','aigc_image_task','aigc_video_result','aigc_video_task','aigc_music_result','aigc_music_task'] as $table) Db::name($table)->where(['tenant_id'=>94011,'user_id'=>95011])->delete();
        Db::name('tenant_point_log')->where('tenant_id',94011)->delete();
        Db::name('user_account_log')->where('user_id',95011)->delete();
    }
    if ($canvasId) Db::name('aigc_short_drama_canvas')->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011])->delete();
    foreach (array_reverse($inserted) as [$table,$id]) Db::name($table)->where('id',$id)->delete();
}
