<?php
// Isolated fixtures, rollback only; no providers or physical-file deletion.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\WorkspaceAssetService as W;
use app\common\service\app\aigc_llm\agent\{AgentStore as S, AgentArtifactService as A};
use app\common\service\open_platform\OpenStore;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$db = config('database.connections.mysql');
check(PHP_SAPI==='cli' && $db['hostname']==='127.0.0.1' && in_array($db['database'], ['x_cn','z_cn'], true), 'Local rollback fixtures only');
Db::startTrans();
try {
    $tenant=1999999875; $user=$tenant; $owner=['tenant_id'=>$tenant,'user_id'=>$user];
    $storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
    $task=Db::name('aigc_image_task')->insertGetId($owner+['status'=>'success','create_time'=>time()]);
    $run=9875;
    $step=S::table('step')->insertGetId($owner+['run_id'=>$run,'step_key'=>hash('sha256',random_bytes(8)),'tool_code'=>'aigc_image','title'=>'fixture','task_id'=>$task,'status'=>'success','prepared'=>'{}','result'=>'{}','create_time'=>time(),'update_time'=>time()]);
    $ids=[];
    for ($i=0; $i<2; $i++) {
        $uri='uploads/deletion-fixture-'.$i.'.png';
        $ids[]=Db::name('aigc_image_result')->insertGetId($owner+$storage+['task_id'=>$task,'image_uri'=>$uri,'create_time'=>time()]);
        $file='of_'.bin2hex(random_bytes(16));
        OpenStore::table('file')->insert($owner+$storage+['file_id'=>$file,'uri'=>$uri,'name'=>'fixture','mime'=>'image/png','size'=>0]+OpenStore::timestamp());
        S::table('artifact')->insert($owner+$storage+['session_id'=>$tenant,'run_id'=>$run,'step_id'=>$step,'identity_hash'=>hash('sha256',$file),'title'=>'fixture','kind'=>'image','data'=>S::encode(['file_id'=>$file]),'create_time'=>time()]);
    }
    check(count(A::lists($tenant,$user,$tenant))===2, 'Two live Agent artworks');
    W::update($tenant,$user,['refs'=>['image:result:'.$ids[0]],'action'=>'trash']);
    check(count(A::lists($tenant,$user,$tenant))===1, 'Deleted artwork must disappear from Agent');
    check((int)Db::name('aigc_image_task')->where('id',$task)->value('delete_time')===0, 'Sibling output keeps task visible');
    W::update($tenant,$user,['refs'=>['image:result:'.$ids[1]],'action'=>'trash']);
    check(A::lists($tenant,$user,$tenant)===[], 'All outputs deleted from Agent');
    check((int)Db::name('aigc_image_task')->where('id',$task)->value('delete_time')>0, 'Empty completed task disappears from history');
    W::update($tenant,$user,['refs'=>['image:result:'.$ids[0]],'action'=>'restore']);
    check(count(A::lists($tenant,$user,$tenant))===1, 'Restoring returns only the selected output');
    check((int)Db::name('aigc_image_task')->where('id',$task)->value('delete_time')===0, 'Restoring reopens task history');
    W::update($tenant,$user,['refs'=>['image:task:'.$task],'action'=>'trash']);
    W::update($tenant,$user,['refs'=>['image:task:'.$task],'action'=>'restore']);
    check(count(A::lists($tenant,$user,$tenant))===1, 'Task restore must not revive separately trashed siblings');
    $ref='image:result:'.$ids[0];
    Db::name('user_asset_state')->where($owner+['asset_ref'=>$ref])->update(['trashed_at'=>time()]);
    W::synchronizeDeleted($tenant,$user);
    check(A::lists($tenant,$user,$tenant)===[], 'Legacy library-only deletion is repaired');
    check(Db::name('aigc_image_result')->whereIn('id',$ids)->count()===2, 'Source rows retained for recovery');
    check(OpenStore::owner('file',$tenant,$user)->count()===2, 'Physical-file records retained');
    echo "PASS shared trash/restore, Agent visibility, partial outputs, history, and legacy deletion repair; rolled back\n";
} finally { Db::rollback(); }
