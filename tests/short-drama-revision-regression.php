<?php
// Deterministic revision/read-path checks; no provider, billing or user-data writes.
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service,ShortDramaRevisionScope as Scope};
(new think\App())->initialize();
set_error_handler(static function($level,$message,$file,$line) { if($level & (E_WARNING|E_NOTICE)) throw new ErrorException($message,0,$level,$file,$line); return false; });
function revisionCheck($yes,$message) { if(!$yes) throw new RuntimeException($message); }
function invokeRevision($name,...$args) { return (new ReflectionMethod(Service::class,$name))->invoke(null,...$args); }
$base=['title'=>'Fixture','locations'=>[['id'=>'late','story_order'=>1],['id'=>'first','story_order'=>2]],'storyboard'=>[
    ['shot_id'=>'s1_1','scene_ref_id'=>'first','dialogue'=>'第一句','visual_description'=>'初始画面','composition'=>'构图','camera_movement'=>'运镜','image_prompt'=>'原图片提示词','video_prompt'=>'原视频提示词','recommended_duration_seconds'=>5],
    ['shot_id'=>'s3_1','scene_ref_id'=>'late','dialogue'=>'另一句','visual_description'=>'另一画面','recommended_duration_seconds'=>5],
]];
$target=Scope::shotTarget('把分镜1的对白给我改成英文的。',$base);
revisionCheck($target['id']==='s1_1','Displayed first shot must not follow location-library order');
$sorted=$base;$sorted['storyboard'][0]['sort']=2;$sorted['storyboard'][1]['sort']=1;
revisionCheck(Scope::shotTarget('分镜1对白改英文',$sorted)['id']==='s3_1','Explicit global sort matches the UI');
foreach (['对白'=>'dialogue','构图'=>'composition','运镜'=>'camera_movement','图片提示词'=>'image_prompt','视频提示词'=>'video_prompt','画面'=>'visual_description'] as $label=>$field) {
    $selection=Scope::shotTarget('把分镜1的'.$label.'改成英文',$base);
    $changed=$base['storyboard'][0];foreach($selection['fields'] as $name)$changed[$name]='English text: keep this exact value.';
    $next=Scope::mergeShots($base,['storyboard'=>[$changed]],$selection);
    revisionCheck($next['storyboard'][0][$field]==='English text: keep this exact value.',$label.' English revision applies');
    revisionCheck($next['storyboard'][1]===$base['storyboard'][1],$label.' does not rewrite other shots');
}
$payload=['description'=>['lighting'=>'warm'],'revision_target'=>['action'=>['move left','stop']],'nested'=>['prompt'=>'Slowly pan left.']];
$normalized=invokeRevision('localizeGenerationTaskPayload',$payload);
revisionCheck($normalized['description']===$payload['description'],'Structured metadata stays structured');
revisionCheck($normalized['nested']['prompt']==='Slowly pan left.','Payload read preserves authored English');
revisionCheck(invokeRevision('localizeGenerationPromptText','  Slowly pan left.  ')==='Slowly pan left.','Authored text is not silently translated');
$english=array_replace($base['storyboard'][0],['dialogue'=>'Where is the bug?','composition'=>'Medium shot.','camera_movement'=>'Slowly pan left.','image_prompt'=>'A warm office at night.','video_prompt'=>'The programmer opens a laptop.']);
$formatted=invokeRevision('formatShot',$english);
foreach(['dialogue','composition','camera_movement','image_prompt'] as $field)revisionCheck($formatted[$field]===$english[$field],$field.' survives saved-shot readback');
revisionCheck(str_contains($formatted['video_prompt'],'The programmer opens a laptop.'),'Video prompt retains English content');
$editable=invokeRevision('editableShotData',$english);
foreach(['image_prompt','video_prompt','dialogue','composition','camera_movement'] as $field)revisionCheck($editable[$field]===$english[$field],$field.' survives edit normalization');
echo "PASS: displayed shot numbering, English revisions, scope preservation, structured metadata, saved text readback\n";
