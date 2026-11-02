<?php
// Synthetic local records in one rolled-back transaction. No providers or filesystem writes.
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
use think\facade\Db;
use app\common\service\WorkspaceShortDramaAssetService as Provenance;
use app\common\service\WorkspaceAssetService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
$checks=0;
$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$db=config('database.connections.mysql');
$check(PHP_SAPI==='cli' && $db['hostname']==='127.0.0.1' && in_array($db['database'],['x_cn','z_cn'],true),'Local rollback fixtures only');
Db::startTrans();
try {
    $tenant=1999999651;$user=$tenant;$owner=['tenant_id'=>$tenant,'user_id'=>$user];
    $uri='uploads/provenance-fixture.png';$task='sd_gen_provenance_fixture';
    $generation=$owner+['task_id'=>$task,'task_type'=>'subject_image','status'=>'success','consumption_id'=>1999999651,'provider_task_id'=>'upstream-media-real',
        'request_json'=>json_encode(['params'=>['model_name'=>'Image snapshot','ratio'=>'16:9','quality'=>'1k','prompt'=>'Original user prompt','api_key'=>'never expose'],'image_params'=>['quantity'=>1,'api_key'=>'never expose']]),'result_json'=>'{}','create_time'=>100];
    Db::name('aigc_short_drama_generation_task')->insert($generation);
    Db::name('aigc_short_drama_generation_task')->insert($owner+['task_id'=>'sd_plan_fixture','task_type'=>'script_plan','status'=>'success','request_json'=>'{"model_name":"Qwen planning","prompt":"Plan this script"}','create_time'=>200]);
    $base=$owner+['project_id'=>0,'task_id'=>$task,'asset_type'=>'subject_image','uri'=>$uri,'title'=>'fixture','status'=>'ready','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','create_time'=>100,'delete_time'=>0,'meta_json'=>json_encode(['consumption_id'=>1999999651])];
    $original=Db::name('aigc_short_drama_asset')->insertGetId($base);
    $copy=Db::name('aigc_short_drama_asset')->insertGetId(array_replace($base,['task_id'=>'sd_plan_fixture','create_time'=>200,'meta_json'=>json_encode(['consumption_id'=>1999999651,'reused_from_asset_id'=>$original])]));
    $local=Db::name('aigc_short_drama_asset')->insertGetId(array_replace($base,['task_id'=>'sd_plan_fixture','uri'=>'uploads/local-fixture.png','meta_json'=>'{"source":"pc_subject_create_manual"}']));
    $list=AigcShortDramaService::assetLists($tenant,$user,['page_size'=>100]);
    $rows=array_column($list['lists'],null,'id');$a=$rows[$original];$b=$rows[$copy];
    $check($list['count']===3,'Do not change project records or source pagination');
    $check($a['library_origin_ref']===$b['library_origin_ref'] && $b['library_origin_ref']==='short_drama:result:'.$original,'Reuse retains original identity');
    $check($b['generation_snapshot']['model_name']==='Image snapshot' && $b['generation_snapshot']['task_id']===$task,'Never read the script model');
    $check($b['generation_snapshot']['prompt']==='Original user prompt' && $b['generation_snapshot']['create_time']===100,'Original prompt and result timestamp');
    $check($b['generation_snapshot']['provider_task_id']==='upstream-media-real','Actual provider ID');
    $check(!str_contains(json_encode($b['generation_snapshot']),'never expose'),'Only presentation parameters leave the service');
    $check($rows[$local]['library_origin']==='upload' && $rows[$local]['generation_snapshot']===[],'Manual subject is an upload without a generated task');
    $foreign=Provenance::appendRows($tenant+1,$user,[$b])[0];
    $check($foreign['generation_snapshot']===[],'Tenant and user scope guard generation reads');
    $other=Provenance::appendRows($tenant,$user,[array_replace($b,['uri'=>'different.png'])])[0];
    $check($other['generation_snapshot']===[],'Consumption alone cannot bind a different output');
    $owned=new ReflectionMethod(WorkspaceAssetService::class,'owned');$owned->setAccessible(true);
    $saved=$owned->invoke(null,$tenant,$user,'short_drama:result:'.$copy);
    $check($saved['raw']['generation_snapshot']['task_id']===$task,'Saved favorites use the same provenance as the main feed');
    $check(Provenance::isUpload(['task_id'=>'','meta'=>['source'=>'short_drama_canvas']]),'Legacy canvas upload');
    $check(!Provenance::isUpload(['task_id'=>'canvas_run_1','meta'=>['source'=>'short_drama_canvas','canvas_run_id'=>1]]),'Generated canvas output stays generated');
    echo "PASS $checks provenance, original metadata, uploads, ownership, safe snapshots and supplemental checks; rolled back\n";
} finally { Db::rollback(); }
