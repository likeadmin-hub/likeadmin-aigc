<?php
namespace app\common\service\power {
 // Admission only: no model request is allowed in this rollback-only test.
 final class MarketTextModelRuntimeService {
  public static array $visionRequests=[];
  public static function resolveAgentModel(int $tenant,string $model,bool $vision=false): array { self::$visionRequests[]=$vision;return []; }
  public static function modelGroups(int $tenant): array { return []; }
 }
}
namespace {
// Local rollback-only reference registration; no file bytes or providers.
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\{OpenAssets,OpenStore,OpenException};
use think\facade\Db;
(new think\App())->initialize();
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$db=config('database.connections.mysql');
if(getenv('WORKSPACE_ASSET_TEST')!=='1'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new RuntimeException('Local fixtures only');
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function denied(callable $call){try{$call();}catch(InvalidArgumentException|OpenException){return;}throw new RuntimeException('Unauthorized asset was accepted');}
function uploadController(string $class,int $tenant,int $user,array $params): array {
 $controller=(new ReflectionClass($class))->newInstanceWithoutConstructor();
 $request=(new \app\Request())->withPost($params);$request->tenantId=$tenant;$request->setMethod('POST');$request->setAction('upload');
 $property=new ReflectionProperty(\app\BaseController::class,'request');$property->setAccessible(true);$property->setValue($controller,$request);
 $property=new ReflectionProperty(\app\api\controller\BaseApiController::class,'userId');$property->setAccessible(true);$property->setValue($controller,$user);
 return json_decode($controller->upload()->getContent(),true);
}
$tenant=1;$user=1999999803;$owner=['tenant_id'=>$tenant,'user_id'=>$user];
$storage=['storage_scope'=>'tenant','storage_engine'=>'oss','storage_domain'=>'https://owned-media.example.invalid'];
Db::startTrans();try{
 $native=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'type'=>10,'upload_origin'=>'local','name'=>'stored.png','uri'=>'uploads/agent-stored.png','create_time'=>time()]+$storage);
 $ref='upload:result:'.$native;
 $count=Db::name('tenant_file')->count();$openCount=OpenStore::table('file')->count();
 $file=OpenAssets::referenceWorkspaceMedia($tenant,$user,$ref);
 $row=OpenStore::owner('file',$tenant,$user)->where('file_id',$file['file_id'])->find();
 check($row['uri']==='uploads/agent-stored.png'&&$row['storage_domain']===$storage['storage_domain'],'Original object identity/storage domain changed');
 check(Db::name('tenant_file')->count()===$count,'A stored asset was uploaded again');
 check(OpenStore::table('file')->count()===$openCount+1,'Reference must register only one owned file');
 check(OpenAssets::referenceWorkspaceMedia($tenant,$user,$ref)['file_id']===$file['file_id'],'Retry created a new reference');
 check(OpenStore::table('file')->count()===$openCount+1,'Retry duplicated the reference');
 foreach([\app\api\controller\app\aigc_llm\AgentController::class,\app\api\controller\app\aigc_canvas\ConversationController::class] as $controller){
  $response=uploadController($controller,$tenant,$user,['asset_id'=>$ref,'project_id'=>0,'page_id'=>'entry']);
  check($response['code']===1&&$response['data']['file_id']===$file['file_id'],'Actual Agent/canvas upload JSON branch failed');
  check(uploadController($controller,$tenant,$user+1,['asset_id'=>$ref,'project_id'=>0,'page_id'=>'entry'])['code']===0,'Controller accepted a foreign stored reference');
 }
 $params=['request_id'=>'stored_reference_'.bin2hex(random_bytes(12)),'content'=>'仅验证已存图片引用，不执行模型','attachments'=>[$file['file_id']],'preferences'=>['mode'=>'ask','models'=>['text'=>'offline-admission-fixture']]];
 $run=\app\common\service\app\aigc_llm\agent\AgentExecutionService::submit($tenant,$user,$params);
 check($run['attachments'][0]['file_id']===$file['file_id']&&$run['attachments'][0]['name']==='stored.png','Agent submission lost the registered reference');
 check($run['status']==='queued'&&Db::name('tenant_file')->count()===$count,'Admission uploaded or generated media');
 $svg=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'type'=>10,'upload_origin'=>'local','name'=>'stored.svg','uri'=>'uploads/agent-stored.svg','create_time'=>time()]+$storage);
 $svgFile=OpenAssets::referenceWorkspaceMedia($tenant,$user,'upload:result:'.$svg);
 check(str_starts_with($svgFile['mime'],'image/'),'Authorized SVG lost image MIME routing');
 \app\common\service\app\aigc_llm\agent\AgentExecutionService::submit($tenant,$user,array_replace($params,['request_id'=>'stored_svg_'.bin2hex(random_bytes(12)),'attachments'=>[$svgFile['file_id']]]));
 check(end(\app\common\service\power\MarketTextModelRuntimeService::$visionRequests)===true,'Stored SVG bypassed vision model admission');
 $svgResult=Db::name('aigc_canvas_asset')->insertGetId($owner+$storage+['asset_type'=>'image','uri'=>'outputs/agent-generated.svg','mime_type'=>'application/octet-stream','status'=>'ready','title'=>'generated SVG','create_time'=>time()]);
 check(str_starts_with(OpenAssets::referenceWorkspaceMedia($tenant,$user,'canvas:result:'.$svgResult)['mime'],'image/'),'Generic stored MIME must retain the authoritative image type');
 foreach([[$tenant+1,$user],[$tenant,$user+1]] as [$t,$u])denied(fn()=>OpenAssets::referenceWorkspaceMedia($t,$u,$ref));
 denied(fn()=>OpenAssets::referenceWorkspaceMedia($tenant,$user,'https://arbitrary.invalid/file.png'));
 Db::name('user_asset_state')->insert($owner+['asset_ref'=>$ref,'hidden_at'=>time()]);
 denied(fn()=>OpenAssets::referenceWorkspaceMedia($tenant,$user,$ref));
 Db::name('user_asset_state')->where($owner+['asset_ref'=>$ref])->delete();
 Db::name('tenant_file')->where('id',$native)->update(['delete_time'=>time()]);
 denied(fn()=>OpenAssets::referenceWorkspaceMedia($tenant,$user,$ref));
 $task=Db::name('aigc_image_task')->insertGetId($owner+['status'=>'success','prompt'=>'owned image','create_time'=>time()]);
 $result=Db::name('aigc_image_result')->insertGetId($owner+$storage+['task_id'=>$task,'image_uri'=>'outputs/agent-result.png','create_time'=>time()]);
 $generated=OpenAssets::referenceWorkspaceMedia($tenant,$user,'image:result:'.$result);
 check(OpenStore::owner('file',$tenant,$user)->where('file_id',$generated['file_id'])->value('uri')==='outputs/agent-result.png','Generated output lost original URI');
 $run=Db::name('aigc_canvas_run')->insertGetId($owner+['project_id'=>1,'run_type'=>'image','status'=>'success','prompt'=>'owned canvas','result_json'=>json_encode(['results'=>[['image_url'=>'https://example.invalid/canvas-a.png'],['image_url'=>'https://example.invalid/canvas-b.png']]]),'create_time'=>time()]);
 $canvas=OpenAssets::referenceWorkspaceMedia($tenant,$user,"canvas:task:$run:2");
 check($canvas['url']==='https://example.invalid/canvas-b.png','Wrong expanded canvas output selected');
 denied(fn()=>OpenAssets::referenceWorkspaceMedia($tenant,$user,"canvas:task:$run:3"));
 echo "PASS owned stored upload/generated/canvas references, idempotence, tenant/user/deletion isolation, no second upload; rolled back\n";
}finally{Db::rollback();}
}
