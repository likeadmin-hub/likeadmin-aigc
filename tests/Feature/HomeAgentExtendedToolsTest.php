<?php
// Local rollback fixtures; no provider requests, billing or physical files.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentCapabilityCatalog as C,AgentToolContext as T,AgentArtifactService as A};
use app\common\service\open_platform\{OpenSchema,OpenVault,OpenStore};
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject(callable $call){try{$call();}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException $e){return;}throw new RuntimeException('Unsafe input accepted');}
$file='of_'.str_repeat('a',32);
foreach(['aigc_music'=>['prompt'=>'背景音乐','instrumental'=>true,'duration'=>30],'aigc_fitting'=>['garment_images'=>[$file],'model_images'=>[$file],'quantity'=>1]] as $code=>$input){
 OpenSchema::validateRequest($input,C::schema($code),true);
 foreach(['tenant_id'=>99,'user_id'=>99,'callback_url'=>'https://example.test','api_key'=>'secret','reference_asset_id'=>1] as $field=>$value)reject(fn()=>OpenSchema::validateRequest($input+[$field=>$value],C::schema($code),true));
}
reject(fn()=>OpenSchema::validateRequest(['prompt'=>'test','duration'=>1],C::schema('aigc_music'),true));
reject(fn()=>OpenSchema::validateRequest(['garment_images'=>[$file]],C::schema('aigc_fitting'),true));
reject(fn()=>OpenSchema::validateRequest(['garment_images'=>['https://example.test/a.png'],'model_images'=>[$file]],C::schema('aigc_fitting'),true));
check(T::music(1,1,123)===null,'Native music unexpectedly requires Agent tables');
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
Db::startTrans();try{
 $tenant=1999999902;$user=$tenant;$run=$tenant;$task=$tenant;
 $selection=['channel'=>['code'=>'chosen','name'=>'Selected music','api_key'=>'private'],'spec'=>['quality'=>'standard']];
 $prepared=['run_id'=>$run,'capability'=>['code'=>'aigc_music'],'quote'=>['user'=>12,'tenant'=>6,'snapshot'=>['quote'=>['user_charge_points'=>12,'tenant_cost_points'=>6],'native_selection_blob'=>OpenVault::seal(S::encode(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_music','selection'=>$selection]))]]];
 $step=S::table('step')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'run_id'=>$run,'step_key'=>hash('sha256','extended-music'),'title'=>'music','tool_code'=>'aigc_music','task_id'=>0,'status'=>'submitting','prepared'=>S::encode($prepared),'result'=>'{}','create_time'=>time(),'update_time'=>time()]);
 $prepared['step_id']=$step;
 T::within($prepared,function()use($tenant,$user,$task,$step){
  check(T::music($tenant,$user)['quote']['user_charge_points']===12,'Frozen price changed');
  T::bindMusicTask($tenant,$user,$task);
  check((int)S::table('step')->where('id',$step)->value('task_id')===$task,'Task not durably bound');
  try{T::music($tenant,$user+1);throw new LogicException('Price leaked');}catch(RuntimeException $e){}
  return [];
 });
 check(T::music($tenant,$user)===null,'Process context leaked');
 check(T::music($tenant,$user,$task,$step)['selection']['channel']['code']==='chosen','Restart lost selected channel');
 try{T::music($tenant+1,$user,$task,$step);throw new LogicException('Cross-tenant task price leaked');}catch(RuntimeException $e){}
 // Fitting uses its own price snapshot when delegating to the native image service.
 $fitting=['capability'=>['code'=>'aigc_fitting'],'quote'=>['snapshot'=>['quote'=>['user_charge_points'=>20],'native_selection_blob'=>OpenVault::seal(S::encode(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_fitting','selection'=>['channel'=>['code'=>'selected-image']]]))]]];
 T::within($fitting,function()use($tenant,$user){check(T::image($tenant,$user)['selection']['channel']['code']==='selected-image','Fitting child ignored confirmed image selection');return [];});
 echo "Home Agent extended tools passed: bounded schemas, ownership, durable music prices, fitting image snapshots; rolled back\n";
}finally{Db::rollback();}
