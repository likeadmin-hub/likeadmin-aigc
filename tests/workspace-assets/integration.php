<?php
// Local opt-in only. Fixture writes run in a rollback-only transaction.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use app\common\service\WorkspaceAssetService as Library;
use think\facade\Db;
if (getenv('WORKSPACE_ASSET_TEST') !== '1') throw new RuntimeException('Set WORKSPACE_ASSET_TEST=1 for a local database');
(new \think\App())->initialize();
function check($yes, $message) { if (!$yes) throw new RuntimeException($message); }
function reject(callable $action) { try { $action(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Unauthorized or invalid update accepted'); }
Db::startTrans();
try {
    $tenant=2000000000; $user=2000000000;
    $task=Db::name('aigc_image_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','prompt'=>'Fixture, no provider request','create_time'=>time()]);
    $result=Db::name('aigc_image_result')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,'image_uri'=>'uploads/test-fixture.png','create_time'=>time()]);
    $ref='image:result:'.$result;
    $state=Library::update($tenant,$user,['refs'=>[$ref],'favorite'=>1,'label'=>'todo'])['states'][0];
    check($state['favorite']===1 && $state['label']==='todo', 'Persisted metadata');
    reject(fn()=>Library::update($tenant+1,$user,['refs'=>[$ref],'action'=>'trash']));
    reject(fn()=>Library::update($tenant,$user+1,['refs'=>[$ref],'action'=>'trash']));
    reject(fn()=>Library::update($tenant,$user,['refs'=>[$ref],'action'=>'remove']));
    Library::update($tenant,$user,['refs'=>[$ref],'action'=>'trash']);
    $read=Library::read($tenant,$user);
    check(count($read['supplemental'])===1 && $read['states'][0]['trashed_at']>0,'Trash can be read independently of recent source limits');
    check(count(Library::read($tenant,$user+1)['states'])===0,'Cross-user reads are isolated');
    check((int)Db::name('aigc_image_result')->where('id',$result)->value('delete_time')>0,'Source result soft-deleted across tool pages');
    Library::update($tenant,$user,['refs'=>[$ref],'action'=>'restore']);
    check((int)Library::read($tenant,$user)['states'][0]['trashed_at']===0,'Restore works');
    check((int)Db::name('aigc_image_result')->where('id',$result)->value('delete_time')===0,'Result restored');
    check((int)Db::name('aigc_image_task')->where('id',$task)->value('delete_time')===0,'Task history restored');
    reject(fn()=>Library::update($tenant,$user,['refs'=>[$ref,'image:result:2147483647'],'action'=>'trash']));
    check((int)Library::read($tenant,$user)['states'][0]['trashed_at']===0,'Mixed-ownership batch rolls back entirely');
    Library::update($tenant,$user,['refs'=>[$ref],'action'=>'trash']);
    Library::update($tenant,$user,['refs'=>[$ref],'action'=>'remove']);
    reject(fn()=>Library::update($tenant,$user,['refs'=>[$ref],'action'=>'restore']));
    check(Library::read($tenant,$user)['supplemental']===[],'Removed asset remains a tombstone');
    foreach (['pic_lipsync'=>'aigc_pic_lipsync','product_image'=>'aigc_product_image','video'=>'aigc_video','music'=>'aigc_music','style_transfer'=>'aigc_style_transfer'] as $source=>$table) {
        $taskId=Db::name($table.'_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','create_time'=>time()]);
        $media=in_array($source,['pic_lipsync','video'],true)?'video_uri':($source==='music'?'audio_uri':'image_uri');
        $resultId=Db::name($table.'_result')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$taskId,$media=>'uploads/tool-fixture.'.($source==='pic_lipsync'?'mp4':'png'),'create_time'=>time()]);
        $toolRef=$source.':result:'.$resultId;
        Library::update($tenant,$user,['refs'=>[$toolRef],'label'=>'review']);
        reject(fn()=>Library::update($tenant,$user+1,['refs'=>[$toolRef],'favorite'=>1]));
        $saved=array_values(array_filter(Library::read($tenant,$user)['supplemental'],fn($row)=>$row['ref']===$toolRef));
        check(count($saved)===1 && $saved[0]['source']===$source,'Tool labels preserve their source without requiring a favorite');
        Library::update($tenant,$user,['refs'=>[$toolRef],'action'=>'trash']);
        check((int)Db::name($table.'_result')->where('id',$resultId)->value('delete_time')>0,'Tool source result deleted');
        Library::update($tenant,$user,['refs'=>[$toolRef],'action'=>'restore']);
        check((int)Db::name($table.'_result')->where('id',$resultId)->value('delete_time')===0,'Tool source result restored');
    }
    $secondTask=Db::name('aigc_image_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','prompt'=>'Pagination fixture','create_time'=>time()]);
    Db::name('aigc_image_task')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','prompt'=>'Other live pagination fixture','create_time'=>time()]);
    $firstPage=\app\common\service\app\aigc_image\AigcImageService::taskLists($tenant,$user,['page_no'=>1,'page_size'=>1,'status'=>'success']);
    $secondPage=\app\common\service\app\aigc_image\AigcImageService::taskLists($tenant,$user,['page_no'=>2,'page_size'=>1,'status'=>'success']);
    check((int)$firstPage['count']===2 && count($firstPage['lists'])===1 && count($secondPage['lists'])===1,'Image task pagination preserves older tasks');
    check($firstPage['lists'][0]['id']!==$secondPage['lists'][0]['id'],'Image pages do not repeat');
    foreach (['canvas_image','shot_image'] as $type) Db::name('aigc_short_drama_asset')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>0,'task_id'=>'filter-fixture','asset_type'=>$type,'title'=>'Pagination fixture','uri'=>'uploads/test.png','status'=>'ready','create_time'=>time()]);
    $drama=\app\common\service\app\aigc_short_drama\AigcShortDramaService::assetLists($tenant,$user,['page_no'=>2,'page_size'=>1]);
    check((int)$drama['count']===2 && count($drama['lists'])===1,'Short-drama and canvas results paginate without exclusion');
    $localFile=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>10,'name'=>'local.png','uri'=>'uploads/images/20261012/local.png','upload_origin'=>'local','create_time'=>time()]);
    Library::update($tenant,$user,['refs'=>['upload:result:'.$localFile],'action'=>'trash']);
    check(Library::uploads($tenant,$user,1)['lists']===[],'Local upload removed from shared selectors');
    Library::update($tenant,$user,['refs'=>['upload:result:'.$localFile],'action'=>'restore']);
    check(count(Library::uploads($tenant,$user,1)['lists'])===1,'Local upload restored to selectors');
    $generatedFile=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>10,'name'=>'generated.png','uri'=>'uploads/aigc_image/20261012/generated.png','create_time'=>time()]);
    $webm=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>30,'name'=>'local.webm','uri'=>'uploads/file/local.webm','upload_origin'=>'local','create_time'=>time()]);
    foreach (['mp3','pdf'] as $ext) {
        $nonvisual=Db::name('tenant_file')->insertGetId(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>30,'name'=>'local.'.$ext,'uri'=>'uploads/file/local.'.$ext,'upload_origin'=>'local','create_time'=>time()]);
        reject(fn()=>Library::update($tenant,$user,['refs'=>['upload:result:'.$nonvisual],'favorite'=>1]));
    }
    $files=Library::uploads($tenant,$user,1)['lists'];
    check(count($files)===2 && $files[0]['ref']==='upload:result:'.$webm && $files[1]['ref']==='upload:result:'.$localFile,'Uploads include only explicit local media, not user-owned generated files');
    reject(fn()=>Library::update($tenant,$user,['refs'=>['upload:result:'.$generatedFile],'favorite'=>1]));
    Library::update($tenant,$user,['refs'=>['upload:result:'.$localFile],'favorite'=>1]);
    reject(fn()=>Library::update($tenant+1,$user,['refs'=>['upload:result:'.$localFile],'favorite'=>1]));
    $catalog=\app\common\service\WorkspaceAssetLabelService::class;
    check(count($catalog::read($tenant,$user))===4,'Legacy labels preserved');
    $created=$catalog::change($tenant,$user,['action'=>'create','title'=>'自定义分类','color'=>'#123456']);
    $custom=end($created['labels'])['value'];
    $ref='upload:result:'.$localFile;
    Library::update($tenant,$user,['refs'=>[$ref],'label'=>$custom]);
    $catalog::change($tenant,$user,['action'=>'update','value'=>$custom,'title'=>'重命名分类','color'=>'#654321']);
    check(Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$ref])->value('label')===$custom,'Rename keeps association');
    $renamed=$catalog::read($tenant,$user);
    check(end($renamed)['title']==='重命名分类','Label change persists');
    reject(fn()=>$catalog::change($tenant,$user,['action'=>'create','title'=>'重命名分类','color'=>'#123456']));
    reject(fn()=>$catalog::change($tenant,$user,['action'=>'create','title'=>' ','color'=>'#123456']));
    reject(fn()=>$catalog::change($tenant,$user,['action'=>'create','title'=>'invalid','color'=>'red']));
    reject(fn()=>$catalog::change($tenant+1,$user,['action'=>'delete','value'=>$custom]));
    reject(fn()=>$catalog::change($tenant,$user+1,['action'=>'update','value'=>$custom,'title'=>'other','color'=>'#123456']));
    check(count($catalog::read($tenant+1,$user))===4,'Other tenant sees only own defaults');
    $catalog::change($tenant,$user,['action'=>'delete','value'=>$custom]);
    check(Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$ref])->value('label')==='','Deleting label detaches assets');
    reject(fn()=>Library::update($tenant,$user,['refs'=>[$ref],'label'=>$custom]));
    foreach($catalog::read($tenant,$user) as $label) $catalog::change($tenant,$user,['action'=>'delete','value'=>$label['value']]);
    check($catalog::read($tenant,$user)===[],'Deleted defaults do not reappear');
    reject(fn()=>Library::update($tenant,$user,['refs'=>[$ref],'label'=>'todo']));
    echo "PASS: library persistence, partial patches, tenant/user isolation, trash/restore/remove, atomic batch; no physical files changed\n";
} finally { Db::rollback(); }
