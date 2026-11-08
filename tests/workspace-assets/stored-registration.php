<?php
// Real registration and re-read, rollback only; no media bytes/providers.
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Drama;
use app\common\service\app\aigc_canvas\CanvasAssetService as Canvas;
use app\common\service\FileService;
use think\facade\Db;
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$db=config('database.connections.mysql');
if(getenv('WORKSPACE_ASSET_TEST')!=='1'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new RuntimeException('Local fixtures only');
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function denied(callable $call){try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Foreign source was accepted');}
$tenant=1;$user=1999999803;$owner=['tenant_id'=>$tenant,'user_id'=>$user];
$storage=['storage_scope'=>'tenant','storage_engine'=>'oss','storage_domain'=>'https://owned-media.example.invalid'];
Db::startTrans();try{
 $native=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'type'=>10,'upload_origin'=>'local','name'=>'stored.png','uri'=>'uploads/registration-owned.png','create_time'=>time()]+$storage);
 $foreign=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source_id'=>$user+1,'source'=>1,'type'=>10,'upload_origin'=>'local','name'=>'foreign.png','uri'=>'uploads/foreign.png','create_time'=>time()]+$storage);
 $run=Db::name('aigc_canvas_run')->insertGetId($owner+['project_id'=>1,'run_type'=>'image','status'=>'success','prompt'=>'owned outputs','result_json'=>json_encode(['results'=>[['image_url'=>'https://provider.invalid/a.png'],['image_url'=>'https://provider.invalid/b.png']]]),'create_time'=>time()]);
 $project=Db::name('aigc_short_drama_project')->insertGetId($owner+['title'=>'owned fixture']);
 $canvas=Db::name('aigc_short_drama_canvas')->insertGetId($owner+['title'=>'owned fixture']);
 $count=Db::name('tenant_file')->count();
 $registrations=[
  ['name'=>'drama project','run'=>fn($params)=>Drama::registerAsset($tenant,$user,['project_id'=>$project,'asset_type'=>'reference_image']+$params),'table'=>'aigc_short_drama_asset'],
  ['name'=>'drama canvas','run'=>fn($params)=>Drama::registerAsset($tenant,$user,['canvas_id'=>$canvas,'node_type'=>'image','node_id'=>bin2hex(random_bytes(6))]+$params),'table'=>'aigc_short_drama_asset'],
  ['name'=>'native canvas','run'=>fn($params)=>Canvas::registerAsset($tenant,$user,['asset_type'=>'reference_image']+$params),'table'=>'aigc_canvas_asset'],
 ];
 foreach($registrations as $registration){
  foreach([['upload:result:'.$native,'uploads/registration-owned.png',$storage], ["canvas:task:$run:2",'https://provider.invalid/b.png',['storage_scope'=>'','storage_engine'=>'','storage_domain'=>'']]] as [$ref,$uri,$coords]){
   // Malicious/stale client coordinates must never override the owned source.
   $asset=$registration['run'](['title'=>'saved fixture','uri'=>'https://untrusted.invalid/spoof.png','storage_engine'=>'local','storage_domain'=>'https://untrusted.invalid','mime_type'=>'video/fake','file_size'=>999,'width'=>999,'height'=>999,'duration'=>999,'checksum'=>'spoofed','meta'=>['source_library_id'=>$ref]]);
   $row=Db::name($registration['table'])->where('id',$asset['id'])->find();
   check($row['uri']===$uri,$registration['name'].' trusted client URI instead of source identity');
   foreach(['storage_engine','storage_domain'] as $key)check($row[$key]===$coords[$key],$registration['name'].' lost '.$key);
   check($row['mime_type']===''&&(int)$row['file_size']===0&&(int)$row['width']===0&&(int)$row['height']===0&&(float)$row['duration']===0.0&&$row['checksum']==='',$registration['name'].' retained stale/spoofed client media facts');
   $reloaded=FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
   $expected=str_starts_with($uri,'https://')?$uri:$storage['storage_domain'].'/'.$uri;
   check($asset['url']===$expected&&$reloaded===$expected,$registration['name'].' returned/reloaded a corrupted source URL');
  }
  denied(fn()=>$registration['run'](['uri'=>'uploads/foreign.png','meta'=>['source_library_id'=>'upload:result:'.$foreign]]));
 }
 denied(fn()=>Drama::registerAsset($tenant,$user,['project_id'=>$project,'asset_type'=>'reference_video','meta'=>['source_library_id'=>'upload:result:'.$native]]));
 denied(fn()=>Drama::registerAsset($tenant,$user,['canvas_id'=>$canvas,'node_type'=>'audio','meta'=>['source_library_id'=>'upload:result:'.$native]]));
 denied(fn()=>Canvas::registerAsset($tenant,$user,['asset_type'=>'reference_video','meta'=>['source_library_id'=>'upload:result:'.$native]]));
 $facts=Db::name('aigc_canvas_asset')->insertGetId($owner+$storage+['asset_type'=>'image','uri'=>'outputs/known-facts.jpg','mime_type'=>'image/jpeg','file_size'=>2048,'width'=>640,'height'=>480,'status'=>'ready','title'=>'known facts','create_time'=>time()]);
 $known=Drama::registerAsset($tenant,$user,['project_id'=>$project,'asset_type'=>'reference_image','mime_type'=>'video/fake','file_size'=>999,'width'=>999,'height'=>999,'meta'=>['source_library_id'=>'canvas:result:'.$facts]]);
 check($known['mime_type']==='image/jpeg'&&(int)$known['file_size']===2048&&(int)$known['width']===640&&(int)$known['height']===480,'Known server MIME/size/dimensions were not restored');
 check(Db::name('tenant_file')->count()===$count,'Stored registrations duplicated uploads');
 echo "PASS drama project/canvas and native canvas: authoritative OSS coordinates, external second output, re-read URL, foreign owner rejection, no upload; rolled back\n";
}finally{Db::rollback();}
