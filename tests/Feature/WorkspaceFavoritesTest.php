<?php
// Existing local DB; rollback fixtures only. Never generates media or deletes physical files.
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
use think\facade\Db;
use app\common\service\WorkspaceAssetService as W;
use app\common\service\app\aigc_llm\agent\{AgentStore as S, AgentArtifactService as A};
use app\common\service\open_platform\OpenStore;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function denied(callable $call){try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unauthorized write accepted');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try{
 $tenant=1999999867;$user=$tenant;$owner=['tenant_id'=>$tenant,'user_id'=>$user];$storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
 foreach(['image'=>'aigc_image','video'=>'aigc_video','pic_lipsync'=>'aigc_pic_lipsync','image_human'=>'image_human','digital_human'=>'aigc_digital_human','music'=>'aigc_music'] as $source=>$table){
  $task=Db::name($table.'_task')->insertGetId($owner+['status'=>'success','create_time'=>time()]);
  $field=$source==='image'?'image_uri':($source==='music'?'audio_uri':'video_uri');
  $result=Db::name($table.'_result')->insertGetId($owner+$storage+['task_id'=>$task,$field=>'uploads/favorite-fixture.'.($source==='image'?'png':($source==='music'?'mp3':'mp4')),'create_time'=>time()]);
  $ref=$source.':result:'.$result;
  W::update($tenant,$user,['refs'=>[$ref],'favorite'=>1]);
  $saved=array_values(array_filter(W::read($tenant,$user)['supplemental'],fn($r)=>$r['ref']===$ref));
  check(count($saved)===1&&$saved[0]['raw']['result_id']===$result,'Favorite must be discoverable by exact output identity: '.$source);
  denied(fn()=>W::update($tenant+1,$user,['refs'=>[$ref],'favorite'=>1]));denied(fn()=>W::update($tenant,$user+1,['refs'=>[$ref],'favorite'=>1]));
  W::update($tenant,$user,['refs'=>[$ref],'favorite'=>0]);
  check(!array_filter(W::read($tenant,$user)['supplemental'],fn($r)=>$r['ref']===$ref),'Cancellation persisted');
 }
 foreach(['document','audio'] as $kind){
  $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert($owner+$storage+['file_id'=>$file,'uri'=>'uploads/favorite-fixture.'.($kind==='audio'?'mp3':'md'),'name'=>'favorite fixture','mime'=>$kind==='audio'?'audio/mpeg':'text/markdown','size'=>1]+OpenStore::timestamp());
  $id=S::table('artifact')->insertGetId($owner+$storage+['session_id'=>$tenant,'run_id'=>$tenant,'step_id'=>0,'identity_hash'=>hash('sha256',$file),'title'=>'favorite fixture','kind'=>$kind,'data'=>S::encode(['file_id'=>$file,'content'=>'# Saved content']),'favorite'=>1,'create_time'=>time()]);
  $ref='agent:result:'.$id;
  $saved=array_values(array_filter(W::read($tenant,$user)['supplemental'],fn($r)=>$r['ref']===$ref));check(count($saved)===1,'Legacy Agent favorite backfilled');check($saved[0]['raw']['asset_type']===$kind,'Correct media kind');
  W::update($tenant,$user,['refs'=>[$ref],'favorite'=>0]);
  check(!array_filter(W::read($tenant,$user)['supplemental'],fn($r)=>$r['ref']===$ref),'Old Agent flag must not resurrect canceled favorite');
  $rows=A::lists($tenant,$user,$tenant);check((int)array_values(array_filter($rows,fn($r)=>(int)$r['id']===(int)$id))[0]['favorite']===0,'Agent reflects cancellation in assets');
  A::favorite($tenant,$user,$id,true);check(count(array_filter(W::read($tenant,$user)['supplemental'],fn($r)=>$r['ref']===$ref))===1,'Agent recollection visible in assets');
  denied(fn()=>W::update($tenant,$user+1,['refs'=>[$ref],'favorite'=>1]));
  W::update($tenant,$user,['refs'=>[$ref],'action'=>'trash']);check(!array_filter(A::lists($tenant,$user,$tenant),fn($r)=>(int)$r['id']===(int)$id),'Agent hides trashed artifact');
  W::update($tenant,$user,['refs'=>[$ref],'action'=>'restore']);check(count(array_filter(A::lists($tenant,$user,$tenant),fn($r)=>(int)$r['id']===(int)$id))===1,'Agent restores artifact');
 }
 echo "PASS persisted favorites: image/video/three digital-human sources/music/Agent documents/audio, cancellation, legacy migration, trash/restore and ownership; rolled back\n";
}finally{Db::rollback();}
