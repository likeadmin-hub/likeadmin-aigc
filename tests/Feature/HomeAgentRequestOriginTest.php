<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App(dirname(__DIR__,2).'/'))->initialize();
use app\common\service\app\aigc_llm\agent\AgentRequestOrigin as Origin;
use app\common\service\FileService;
$original=app()->request;
try {
    $server=['HTTP_HOST'=>'localhost','HTTP_X_FORWARDED_HOST'=>'previous.example.test','REQUEST_SCHEME'=>'http','HTTPS'=>'off','OTHER'=>'kept'];
    $request=new app\Request();$request->withServer($server);app()->instance('request',$request);
    $before=FileService::getFileUrlByStorage('uploads/owned.png','platform','local','');
    $request=new app\Request();$request->withServer(Origin::server($server,'https://admitted.example.test'));app()->instance('request',$request);
    $after=FileService::getFileUrlByStorage('uploads/owned.png','platform','local','');
    if($before===$after||$after!=='https://admitted.example.test/uploads/owned.png')throw new RuntimeException('Origin was not restored for original local storage reader');
    foreach(['https://user:pass@example.test','https://example.test/path','https://example.test/?query=1','https://example.test/#fragment',"https://bad\n.example.test"]as$bad)if(Origin::normalize($bad)!==''||Origin::server($server,$bad)!==$server)throw new RuntimeException('Invalid origin changed the request context');
    if(Origin::server($server,'')['OTHER']!=='kept'||Origin::normalize('https://VALID.example.test/')!=='https://valid.example.test')throw new RuntimeException('Legacy context or canonical host changed');
    if($original===app()->request)throw new RuntimeException('Fixture did not use an isolated request');
    think\facade\Db::startTrans();
    try {
        $id='of_'.bin2hex(random_bytes(16));$uri='uploads/origin-'.bin2hex(random_bytes(6)).'.png';
        app\common\service\open_platform\OpenStore::table('file')->insert(['tenant_id'=>1,'user_id'=>1,'file_id'=>$id,'uri'=>$uri,'name'=>'origin.png','mime'=>'image/png','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','create_time'=>time(),'update_time'=>time()]);
        $run=['tenant_id'=>1,'user_id'=>1,'preferences'=>'{}','attachments'=>json_encode([['file_id'=>$id,'url'=>'https://legacy.example.test/'.$uri]])];
        if(Origin::fromRun($run)!=='https://legacy.example.test')throw new RuntimeException('Owned local legacy URL origin not restored');
        $run['user_id']=2;if(Origin::fromRun($run)!=='')throw new RuntimeException('Foreign local origin was reused');$run['user_id']=1;
        app\common\service\open_platform\OpenStore::table('file')->where('file_id',$id)->update(['storage_engine'=>'oss']);
        if(Origin::fromRun($run)!=='')throw new RuntimeException('Cloud signed address used as a local origin');
        $run['preferences']=json_encode(['_request_origin'=>'https://admitted.example.test']);if(Origin::fromRun($run)!=='https://admitted.example.test')throw new RuntimeException('Admission origin snapshot changed');
    } finally { think\facade\Db::rollback(); }
    echo "Home Agent request origin passed: original storage reader, forwarded host isolation, safe origin shape and empty legacy compatibility; legacy owned/cloud/foreign guards rolled back; no task or wallet writes\n";
}finally{app()->instance('request',$original);}
