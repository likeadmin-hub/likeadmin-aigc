<?php
// Local transactional fixtures. No media is generated or written to storage.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use app\common\service\WorkspaceMediaPickerService as Picker;
use think\facade\Db;

(new think\App())->initialize();
$db = config('database.connections.mysql');
if (getenv('WORKSPACE_ASSET_TEST') !== '1' || $db['hostname'] !== '127.0.0.1' || !in_array($db['database'],['x_cn','z_cn'],true)) throw new RuntimeException('Local fixtures only');
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$tenant=1; $user=1999999803;
$owner=['tenant_id'=>$tenant,'user_id'=>$user];
$storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
$upload = static function ($time, $name, $changes=[]) use ($tenant,$user,$storage) {
    return Db::name('tenant_file')->insertGetId($changes+['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'type'=>10,'upload_origin'=>'local','name'=>$name,'uri'=>'uploads/picker.png','create_time'=>$time]+$storage);
};
$page = static fn($tab, $params=[])=>Picker::page($tenant,$user,['tab'=>$tab]+$params);
Db::startTrans();
try {
    $expected=[];
    for ($i=0;$i<47;$i++) {
        $time=100+(($i*7)%4);
        $expected[]=['id'=>$upload($time,'fixture '.$i),'time'=>$time];
    }
    usort($expected,fn($a,$b)=>[$b['time'],$b['id']]<=>[$a['time'],$a['id']]);
    $upload(400,'different account',['source_id'=>$user+1]);
    $upload(400,'different tenant',['tenant_id'=>$tenant+1]);
    $upload(400,'generated',['upload_origin'=>'']);
    $hidden=$upload(400,'trashed');
    Db::name('user_asset_state')->insert($owner+['asset_ref'=>'upload:result:'.$hidden,'trashed_at'=>300]);
    $first=$page('uploads');
    $upload(999,'new arrival');
    $second=$page('uploads',['cursor'=>$first['next_cursor']]);
    $last=$page('uploads',['cursor'=>$second['next_cursor']]);
    check([count($first['lists']),count($second['lists']),count($last['lists']),$last['next_cursor']] === [20,20,7,null], '20/20/7 and exact end');
    $ids=array_column(array_merge($first['lists'],$second['lists'],$last['lists']),'id');
    check($ids===array_map(fn($r)=>'upload:result:'.$r['id'],$expected),'Global chronology, stable ties and insert-safe continuation');
    check(count($page('uploads',['search'=>'fixture 46'])['lists'])===1,'Search the entire history');
    check(!$page('uploads',['types'=>'audio'])['lists'],'Slot media filter');
    $jpg=$upload(1,'old jpg',['uri'=>'uploads/old.JPG']);
    check(array_column($page('uploads',['formats'=>'jpg,video/*'])['lists'],'id')===['upload:result:'.$jpg],'Older compatible file survives format filtering before the limit');

    $task=Db::name('aigc_image_task')->insertGetId($owner+['status'=>'success','prompt'=>'older image','create_time'=>200]);
    $image=Db::name('aigc_image_result')->insertGetId($owner+$storage+['task_id'=>$task,'image_uri'=>'uploads/picker-image.png','create_time'=>220]);
    $videoTask=Db::name('aigc_video_task')->insertGetId($owner+['status'=>'success','prompt'=>'newer video','create_time'=>210]);
    $video=Db::name('aigc_video_result')->insertGetId($owner+$storage+['task_id'=>$videoTask,'video_uri'=>'uploads/picker-video.mp4','create_time'=>230]);
    $results=[];
    for ($i=1;$i<=24;$i++) $results[]=['image_url'=>"https://example.invalid/picker-$i.png"];
    $run=Db::name('aigc_canvas_run')->insertGetId($owner+['project_id'=>1,'run_type'=>'image','status'=>'success','prompt'=>'canvas run','result_json'=>json_encode(['results'=>$results]),'create_time'=>225]);
    $first=$page('assets');
    $last=$page('assets',['cursor'=>$first['next_cursor']]);
    $ids=array_column(array_merge($first['lists'],$last['lists']),'id');
    $expected=['video:result:'.$video];
    for ($i=1;$i<=24;$i++) $expected[]="canvas:task:$run:$i";
    $expected[]='image:result:'.$image;
    check($ids===$expected && count($first['lists'])===20 && $last['next_cursor']===null,'Mixed-source chronology and resume within a canvas run');
    check(array_column($page('assets',['types'=>'video'])['lists'],'id')===['video:result:'.$video],'Filter before paging');
    check(array_column($page('assets',['search'=>'older'])['lists'],'id')===['image:result:'.$image],'Search finds older assets');
    echo "PASS chronological uploads and assets, tenant and user isolation, tie handling, concurrent inserts, search, filters and multiple outputs per canvas run\n";
} finally { Db::rollback(); }
