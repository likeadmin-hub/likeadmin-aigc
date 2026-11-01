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
    echo "Home Agent request origin passed: original storage reader, forwarded host isolation, safe origin shape and empty legacy compatibility; no task or wallet writes\n";
}finally{app()->instance('request',$original);}
