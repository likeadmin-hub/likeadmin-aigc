<?php
// Local, rollback-only fixtures. No provider calls or physical files are created.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\WorkspaceAssetService as Library;
use app\common\service\app\aigc_llm\agent\{AgentArtifactService as Artifacts,AgentStore as S};
use app\common\service\open_platform\OpenStore;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject(callable $call){try{$call();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid mutation accepted');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
foreach([0,1,'0','1',false,true] as $value)check(Library::normalizeFavorite($value)===(int)$value,'valid encoded favorite rejected');
foreach(['true','false','',null,2,'01','1x',[],0.5] as $value)reject(fn()=>Library::normalizeFavorite($value));
// Exercise the actual application request trim filter, not just integer service input.
$request=new \app\Request();$request->withPost(['refs'=>['image:result:1'],'favorite'=>1]);
check(Library::normalize($request->post())[1]['favorite']===1,'HTTP favorite rejected by trim filter');
$request->withPost(['refs'=>['image:result:1'],'favorite'=>0]);check(Library::normalize($request->post())[1]['favorite']===0,'HTTP unfavorite rejected');
Db::startTrans();try{
 $tenant=1999999900;$user=1999999900;$session=1999999900;$run=1999999900;
 foreach(['image','video'] as $kind){
  $app='aigc_'.$kind;$uri='uploads/favorite-fixture-'.$kind;$storage=['storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>''];
  $task=Db::name($app.'_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','create_time'=>time()]);
  $result=Db::name($app.'_result')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,$kind.'_uri'=>$uri,'create_time'=>time()]+$storage);
  $step=S::table('step')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'run_id'=>$run,'step_key'=>hash('sha256',$kind),'title'=>'fixture','tool_code'=>$app,'task_id'=>$task,'status'=>'success','prepared'=>'{}','result'=>'{}','create_time'=>time(),'update_time'=>time()]);
  $file='of_'.bin2hex(random_bytes(16));OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$file,'uri'=>$uri,'name'=>'fixture','mime'=>'','size'=>0]+$storage+OpenStore::timestamp());
  $artifact=S::table('artifact')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session,'run_id'=>$run,'step_id'=>$step,'identity_hash'=>hash('sha256',$file),'title'=>'fixture','kind'=>$kind,'data'=>S::encode(['file_id'=>$file]),'favorite'=>1,'create_time'=>time()]+$storage);
  $ref=$kind.':result:'.$result;
  $get=static function()use($tenant,$user,$session,$artifact){foreach(Artifacts::lists($tenant,$user,$session) as $row)if((int)$row['id']===(int)$artifact)return $row;throw new RuntimeException('Missing artifact');};
  check($get()['favorite']===1,'Legacy positive favorite lost');
  check((int)Db::name('user_asset_state')->where(['tenant_id'=>$tenant,'user_id'=>$user,'asset_ref'=>$ref])->value('favorite')===1,'Legacy favorite not imported');
  Library::update($tenant,$user,['refs'=>[$ref],'favorite'=>'0']);check($get()['favorite']===0,'Asset-page unfavorite not reflected in Agent');
  Artifacts::favorite($tenant,$user,$artifact,true);check($get()['favorite']===1,'Agent favorite not saved');
  $state=array_values(array_filter(Library::read($tenant,$user)['states'],fn($row)=>$row['asset_ref']===$ref));check((int)$state[0]['favorite']===1,'Agent favorite not reflected in asset page');
  Artifacts::favorite($tenant,$user,$artifact,false);check($get()['favorite']===0,'Agent unfavorite not saved');
  Library::update($tenant,$user,['refs'=>[$ref],'favorite'=>'1']);check($get()['favorite']===1,'Asset-page favorite not reflected in Agent');
  reject(fn()=>Artifacts::favorite($tenant+1,$user,$artifact,false));reject(fn()=>Artifacts::favorite($tenant,$user+1,$artifact,false));
  check(Artifacts::lists($tenant,$user+1,$session)===[],'Artifacts leaked across account');
 }
 echo "Home Agent favorites passed: HTTP flags, legacy linking, bidirectional image/video favorites and ownership; rolled back\n";
}finally{Db::rollback();}
