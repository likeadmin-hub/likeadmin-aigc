<?php
// Read-only serializer regression: no database, network, generated files or paid tasks.
require dirname(__DIR__).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);});
request()->withServer(['HTTP_HOST'=>'customer.example.test','HTTPS'=>'on','SERVER_PORT'=>443]);
request()->setHost('customer.example.test');

use app\common\service\app\aigc_story_commerce\StoryCommerceService;
use app\common\service\app\aigc_store_visit\StoreVisitService;
use app\common\service\app\aigc_product_video\ProductVideoService;
use app\common\service\WorkspaceStoryCommerceAssetService;
use app\common\service\app\aigc_motion_sync\MotionSyncService;

$checks=0;
$check=static function(bool $ok,string $message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$uri='uploads/aigc_video/20261115/recorded.mp4';
$expected='https://customer.example.test/'.$uri;
foreach([StoryCommerceService::class,StoreVisitService::class,ProductVideoService::class,MotionSyncService::class] as $service){
    foreach(['http://localhost','http://127.0.0.1:3000','https://old-site.example.test',''] as $domain){
        foreach([$uri,'http://localhost/'.$uri] as $storedUri){
            $media=['video_uri'=>$storedUri,'video_url'=>'http://localhost/'.$uri,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>$domain,'tenant_id'=>8,'user_id'=>9,'file_id'=>10];
            $out=$service::publicBody(['result'=>['videos'=>[$media]]],8)['result']['videos'][0];
            $check($out['url']===$expected&&$out['video_url']===$expected,$service.' must rebase recorded local media onto the current HTTPS site');
            $check(!array_intersect_key($out,array_flip(['tenant_id','user_id','file_id'])),$service.' must retain public ownership projection');
        }
    }
    $legacy=$service::publicBody(['uri'=>$uri,'video_url'=>'http://localhost/'.$uri,'storage_engine'=>'local','storage_domain'=>'http://localhost'],8);
    $check($legacy['url']===$expected&&$legacy['video_url']===$expected,$service.' must refresh aliases on legacy uri descriptors');
    $check($service::publicBody(['text'=>'unrelated'],8)===['text'=>'unrelated'],'Non-media data stays intact');
}
$job=['id'=>31,'tenant_id'=>8,'user_id'=>9,'project_id'=>3,'action'=>'video','status'=>'success','delete_time'=>0,'create_time'=>1,
    'request_json'=>'{}','result_json'=>json_encode(['videos'=>[['video_uri'=>$uri,'storage_engine'=>'local','storage_scope'=>'platform','storage_domain'=>'http://localhost']]])];
$items=WorkspaceStoryCommerceAssetService::rows(8,9,[$job]);
$check(count($items)===1&&$items[0]['raw']['video_url']===$expected,'The actual asset-library serializer must deliver the current domain');
$check(WorkspaceStoryCommerceAssetService::rows(8,10,[$job])===[],'Foreign-account outputs remain hidden');
echo "PASS $checks local media URL, legacy history, HTTPS and asset-library checks\n";
