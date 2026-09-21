<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;

// JSON-lines bridge to real HTTP inside the internal network. No host port,
// business credentials, arbitrary URLs, provider routes or user profile.
$inserted=[];$canvasId=0;$process=null;
try {
    if (Db::name('tenant')->where('id',94011)->count() || Db::name('user')->where('id',95011)->count() || Db::name('app')->where('code','aigc_short_drama')->count()) throw new RuntimeException('Browser fixture scope is not empty');
    foreach ([
        ['tenant',['id'=>94011,'sn'=>'browser-fixture','name'=>'Isolated browser','create_time'=>time(),'delete_time'=>null]],
        ['user',['id'=>95011,'sn'=>95011,'account'=>'browser-fixture','tenant_id'=>94011]],
        ['user_session',['tenant_id'=>94011,'user_id'=>95011,'token'=>'isolated-browser-http','terminal'=>4,'expire_time'=>time()+3600]],
        ['app',['code'=>'aigc_short_drama','status'=>'installed']],
        ['tenant_app',['tenant_id'=>94011,'app_code'=>'aigc_short_drama','buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+3600]],
    ] as [$table,$row]) $inserted[]=[$table,Db::name($table)->insertGetId($row)];
    $canvasId=Canvas::create(94011,95011,['title'=>'HTTP browser fixture'])['id'];
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:19080','-t',app()->getRootPath().'public',__DIR__.'/http_router.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
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
        if (!in_array($action,['current','save'],true) || !in_array($method,['GET','POST'],true) || (int)($body['id']??0)!==$canvasId) throw new RuntimeException('Request outside isolated browser fixture');
        $url='http://127.0.0.1:19080/api/app.aigc_short_drama.canvas/'.$action.'?tenant_id=94011';
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
    if ($canvasId) Db::name('aigc_short_drama_canvas')->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011])->delete();
    foreach (array_reverse($inserted) as [$table,$id]) Db::name($table)->where('id',$id)->delete();
}
