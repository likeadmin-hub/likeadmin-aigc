<?php
// Local fixtures only; rollback even on failure. No generation, billing or file writes.
require dirname(__DIR__,2).'/vendor/autoload.php';
use think\facade\Db;
use app\common\service\ai\GenerationWorkSourceService as Sources;
if (getenv('WORKSPACE_ASSET_TEST') !== '1') throw new RuntimeException('Local test opt-in required');
(new think\App())->initialize();
function expectSource($condition,$message) { if (!$condition) throw new RuntimeException($message); }
Db::startTrans();
try {
    $owner=['tenant_id'=>2000000000,'user_id'=>2000000000];
    $image=static fn()=>Db::name('aigc_image_task')->insertGetId($owner+['status'=>'success','create_time'=>time()]);
    $plain=$image(); $canvas=$image(); $tool=$image(); $stringChild=$image(); $legacy=$image();
    $run=Db::name('aigc_short_drama_canvas_run')->insertGetId($owner+['canvas_id'=>0,'node_id'=>'fixture','node_type'=>'image','provider_task_id'=>(string)$canvas,'request_json'=>'{}','result_json'=>'{}']);
    // Foreign owners must not claim the plain task, even with an identical numeric ID.
    foreach (['tenant_id','user_id'] as $field) {
        $foreign=$owner; $foreign[$field]--;
        Db::name('aigc_short_drama_canvas_run')->insert($foreign+['canvas_id'=>0,'node_id'=>'fixture','node_type'=>'image','provider_task_id'=>(string)$plain,'request_json'=>'{}','result_json'=>'{}']);
    }
    $toolId=Db::name('aigc_product_suite_task')->insertGetId($owner+['image_task_ids'=>json_encode([$tool,(string)$stringChild]),'status'=>'success']);
    $legacyKey='fixture-legacy-image';
    Db::name('aigc_short_drama_generation_task')->insert($owner+['task_id'=>$legacyKey,'task_type'=>'subject_image','request_json'=>'{}','result_json'=>json_encode(['image_task_id'=>$legacy]),'provider_task_id'=>'not-a-local-id']);
    // A market canvas app-task ID may collide with a base image task. It is not a relationship.
    Db::name('aigc_canvas_run')->insert($owner+['project_id'=>0,'node_id'=>'fixture','run_type'=>'image','source_app_code'=>'power_market_image','source_task_id'=>$plain,'params_json'=>'{}','result_json'=>'{}']);
    $rows=Db::name('aigc_image_task')->where($owner)->whereIn('id',[$plain,$canvas,$tool,$stringChild,$legacy])->select()->toArray();
    $origins=array_column(Sources::appendRows($rows,'aigc_image'),'creation_source','id');
    expectSource($origins[$plain]['app_code']==='aigc_image','Unrelated and foreign jobs keep their own source');
    expectSource($origins[$canvas]===['app_code'=>'aigc_short_drama','task_id'=>'canvas_run_'.$run],'Canvas owns its image execution');
    foreach ([$tool,$stringChild] as $id) expectSource($origins[$id]===['app_code'=>'aigc_product_suite','task_id'=>(string)$toolId],'JSON string and numeric child IDs resolve');
    expectSource($origins[$legacy]['task_id']===$legacyKey,'Legacy explicit image mapping resolves');
    foreach (['','t'] as $alias) {
        $q=Db::name('aigc_image_task'); if($alias) $q->alias($alias);
        $q->where($alias ? ['t.tenant_id'=>$owner['tenant_id'],'t.user_id'=>$owner['user_id']] : $owner);
        Sources::ownOnly($q,'aigc_image',$alias);
        expectSource((int)(clone $q)->count()===1,'Own count excludes tools before pagination');
        expectSource((int)$q->limit(1)->value($alias ? 't.id' : 'id')===$plain,'Own history keeps its real task');
    }
    $video=Db::name('aigc_video_task')->insertGetId($owner+['status'=>'success']);
    Db::name('aigc_short_drama_canvas_run')->insert($owner+['canvas_id'=>0,'node_id'=>'fixture-video','node_type'=>'video','provider_task_id'=>(string)$video,'request_json'=>'{}','result_json'=>'{}']);
    $vq=Db::name('aigc_video_task')->where($owner); Sources::ownOnly($vq,'aigc_video');
    expectSource((int)$vq->count()===0,'Short drama video does not enter Video Creation');
    $imageResult=Db::name('aigc_image_result')->insertGetId($owner+['task_id'=>$canvas,'image_uri'=>'uploads/source-fixture.png']);
    $canvasAsset=Db::name('aigc_short_drama_asset')->insertGetId($owner+['project_id'=>0,'task_id'=>'canvas_run_'.$run,'asset_type'=>'canvas_image','title'=>'Fixture','uri'=>'uploads/source-fixture.png','status'=>'ready']);
    $refs=['short_drama:result:'.$canvasAsset,'image:result:'.$imageResult];
    $library=\app\common\service\WorkspaceAssetService::class;
    $library::update($owner['tenant_id'],$owner['user_id'],['refs'=>$refs,'favorite'=>1]);
    foreach (['trash','restore'] as $action) {
        $states=$library::update($owner['tenant_id'],$owner['user_id'],['refs'=>$refs,'action'=>$action])['states'];
        expectSource(count($states)===2,'Both linked asset states are updated');
        foreach ($states as $state) expectSource(($action==='trash')===((int)$state['trashed_at']>0),'Linked trash/restore state is consistent');
    }
    expectSource((int)Db::name('aigc_image_result')->where('id',$imageResult)->value('delete_time')===0,'Linked result restored without deleting files');
    echo "work sources: ownership, JSON mappings, namespace collisions, image/video history and pagination passed\n";
} finally { Db::rollback(); }
