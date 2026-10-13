<?php
// Rollback-only fixtures: validate parent/child task mapping without generating images.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentArtifactService as A,AgentStore as S};
use app\common\service\open_platform\OpenStore;
use app\common\service\WorkspaceAssetService as Library;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
Db::startTrans();try{
 $tenant=1999999903;$user=$tenant;$session=$tenant;$run=$tenant;
 $owner=['tenant_id'=>$tenant,'user_id'=>$user];$storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
 $child=Db::name('aigc_image_task')->insertGetId($owner+['status'=>'success','prompt'=>'Actual submitted fitting prompt','quality'=>'1k','ratio'=>'3:4','quantity'=>1,'model_json'=>S::encode(['display_name'=>'Confirmed image model','api_key'=>'private-fixture']),'create_time'=>time()]);
 $uri='uploads/fitting-fixture.png';
 $result=Db::name('aigc_image_result')->insertGetId($owner+$storage+['task_id'=>$child,'image_uri'=>$uri,'create_time'=>time()]);
 $task=Db::name('aigc_fitting_task')->insertGetId($owner+['status'=>'success','image_task_id'=>$child,'image_task_ids'=>S::encode([$child]),'prompt'=>'Parent template, not the submitted image prompt','create_time'=>time()]);
 $step=S::table('step')->insertGetId($owner+['run_id'=>$run,'step_key'=>hash('sha256','fitting-artifact'),'title'=>'AI试衣','tool_code'=>'aigc_fitting','task_id'=>$task,'status'=>'success','prepared'=>'{}','result'=>'{}','create_time'=>time(),'update_time'=>time()]);
 $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert($owner+$storage+['file_id'=>$file,'uri'=>$uri,'name'=>'fixture','mime'=>'image/png','size'=>0]+OpenStore::timestamp());
 $artifact=S::table('artifact')->insertGetId($owner+$storage+['session_id'=>$session,'run_id'=>$run,'step_id'=>$step,'identity_hash'=>hash('sha256',$file),'title'=>'AI试衣','kind'=>'image','data'=>S::encode(['file_id'=>$file]),'create_time'=>time()]);
 $get=fn()=>A::lists($tenant,$user,$session)[0];
 $meta=$get()['generation'];check($meta['prompt']==='Actual submitted fitting prompt'&&$meta['model_name']==='Confirmed image model'&&$meta['ratio']==='3:4','Fitting detail did not use the actual child task');
 check(!str_contains(S::encode($meta),'private-fixture'),'Private model snapshot exposed');
 $ref='image:result:'.$result;A::favorite($tenant,$user,$artifact,true);
 check((int)Db::name('user_asset_state')->where($owner+['asset_ref'=>$ref])->value('favorite')===1,'Fitting favorite not shared with image asset');
 Library::update($tenant,$user,['refs'=>[$ref],'favorite'=>0]);check($get()['favorite']===0,'Asset unfavorite did not reach Agent');
 Db::name('aigc_image_task')->where('id',$child)->update(['user_id'=>$user+1]);check($get()['generation']===null,'Foreign child task metadata exposed');
 Db::name('aigc_image_task')->where('id',$child)->update(['user_id'=>$user]);
 Db::name('aigc_fitting_task')->where('id',$task)->update(['image_task_id'=>0,'image_task_ids'=>'[]']);check($get()['generation']===null,'Unlinked image result reused');
 echo "Home Agent fitting artifacts passed: original submitted details, private fields, linked image ownership and shared favorites; rolled back\n";
}finally{Db::rollback();}
