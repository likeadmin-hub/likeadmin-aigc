<?php
// Owned audio registration/re-read, rollback only; no uploads or model requests.
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_music\AigcMusicAssetService as Music;
use think\facade\Db;
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$db=config('database.connections.mysql');
if(getenv('WORKSPACE_ASSET_TEST')!=='1'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new RuntimeException('Local fixtures only');
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function denied(callable $call){try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid source was accepted');}
function audioController(int $tenant,int $user,array $params,string $action='upload_audio'): array {
 $controller=(new ReflectionClass(\app\api\controller\app\aigc_pic_lipsync\AssetController::class))->newInstanceWithoutConstructor();
 $request=(new \app\Request())->withPost($params);$request->tenantId=$tenant;$request->setMethod('POST');
 $property=new ReflectionProperty(\app\BaseController::class,'request');$property->setAccessible(true);$property->setValue($controller,$request);
 $property=new ReflectionProperty(\app\api\controller\BaseApiController::class,'userId');$property->setAccessible(true);$property->setValue($controller,$user);
 return json_decode($controller->$action()->getContent(),true);
}
$tenant=1;$user=1999999803;$owner=['tenant_id'=>$tenant,'user_id'=>$user];
$storage=['storage_scope'=>'tenant','storage_engine'=>'oss','storage_domain'=>'https://owned-audio.example.invalid'];
Db::startTrans();try{
 $task=Db::name('aigc_music_task')->insertGetId($owner+['status'=>'success','title'=>'owned audio','create_time'=>time()]);
 $result=Db::name('aigc_music_result')->insertGetId($owner+$storage+['task_id'=>$task,'title'=>'owned song','audio_uri'=>'outputs/owned-song.mp3','mime_type'=>'audio/mpeg','file_size'=>2048,'duration'=>15.5,'create_time'=>time()]);
 $ref='music:result:'.$result;$count=Db::name('tenant_file')->count();
 $asset=Music::referenceWorkspaceAudio($tenant,$user,$ref);
 $row=Db::name('aigc_music_asset')->where('id',$asset['id'])->find();
 check($row['asset_type']==='pic_lipsync_audio'&&$row['uri']==='outputs/owned-song.mp3','Driver audio source/type changed');
 check($row['storage_engine']==='oss'&&$row['storage_domain']===$storage['storage_domain'],'Authoritative audio storage was lost');
 check($row['mime_type']==='audio/mpeg'&&(int)$row['file_size']===2048&&(float)$row['duration']===15.5,'Server-owned audio facts were lost');
 check(Music::assetUrl($row)===$storage['storage_domain'].'/outputs/owned-song.mp3'&&$asset['url']===Music::assetUrl($row),'Driver audio URL corrupt after re-read');
 $response=audioController($tenant,$user,['asset_id'=>$ref]);
 check($response['code']===1&&$response['data']['asset_type']==='pic_lipsync_audio','Actual driver upload_audio JSON branch failed');
 $listed=audioController($tenant,$user,[],'audios');
 check($listed['code']===1&&$listed['data'][0]['url']===$asset['url'],'Actual driver audio list lost original source URL');
 check(audioController($tenant,$user+1,['asset_id'=>$ref])['code']===0,'Driver controller accepted foreign source');
 check(Db::name('tenant_file')->count()===$count,'Audio reference uploaded bytes again');
 foreach([[$tenant+1,$user],[$tenant,$user+1]] as [$t,$u])denied(fn()=>Music::referenceWorkspaceAudio($t,$u,$ref));
 $image=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'type'=>10,'upload_origin'=>'local','name'=>'image.png','uri'=>'uploads/owned.png','create_time'=>time()]+$storage);
 denied(fn()=>Music::referenceWorkspaceAudio($tenant,$user,'upload:result:'.$image));
 Db::name('user_asset_state')->insert($owner+['asset_ref'=>$ref,'hidden_at'=>time()]);
 denied(fn()=>Music::referenceWorkspaceAudio($tenant,$user,$ref));
 echo "PASS owned driver audio: source MIME/size/duration/storage, asset type, re-read URL, tenant/user/type/visibility isolation, no upload; rolled back\n";
}finally{Db::rollback();}
