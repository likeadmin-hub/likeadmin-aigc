<?php
/** Authorized local image, video and music acceptance with draft verification tickets. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenKeyService as K;
use app\common\service\open_platform\OpenToolAdapter as A;
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenMaterials as M;
use app\common\service\open_platform\OpenVerification as V;
use app\common\service\open_platform\OpenContractPreview as Preview;
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');verify(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local DB only');
verify(($argv[1]??'')==='--authorized-1000','Explicit cumulative cap required');
verify(Db::name('user')->where(['id'=>1,'tenant_id'=>1,'account'=>'xhadmin001','is_disable'=>0])->find(),'Authorized local identity missing');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$lock=fopen($dir.'/paid.lock','c');verify($lock&&flock($lock,LOCK_EX|LOCK_NB),'Acceptance lock busy');
$run='local_text_'.bin2hex(random_bytes(6));$key=null;$backup=[];$contracts=[];$report=['run'=>$run,'tenant_id'=>1,'user_id'=>1,'cap'=>1000,'cases'=>[]];
$prior=S::owner('key',1,1)->whereLike('name','local_text_%')->column('id');$spent=$prior?(float)S::table('request')->whereIn('key_id',$prior)->sum('actual_user')+(float)S::table('request')->whereIn('key_id',$prior)->where('active',1)->sum('budget_user'):0.;verify($spent<1000,'Cumulative cap exhausted');$report['prior_spent']=$spent;
$save=function()use(&$report,$dir,$run){file_put_contents($dir.'/'.$run.'.json',S::encode($report));};
$call=function($method,$path,$params=[],$ticket=null)use(&$key){
 $h=curl_init('http://127.0.0.1:19874'.$path);$headers=['Authorization: Bearer '.$key['key'],'Content-Type: application/json'];
 if($ticket){$headers[]='Idempotency-Key: '.$ticket['idempotency_key'];$headers[]='X-Open-Contract-Preview: '.$ticket['preview_token'];}
 if($method==='POST')curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>S::encode($params)]);
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>210,CURLOPT_HTTPHEADER=>$headers]);$body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$error=curl_errno($h);curl_close($h);verify($error===0,'Connection failed; do not repeat uncertain submission');return ['status'=>$status,'body'=>$body,'data'=>S::json((string)$body)];
};
try{
 $asset=S::json(file_get_contents($dir.'/local_text_01400e0a404f.json'))['assets']['image']['file_id']??'';verify($asset!=='','Owned synthetic fixture missing');
 $definitions=[
  'aigc_image'=>['prompt'=>'Photorealistic studio portrait of a fictional adult woman, plain white shirt, neutral background, clear face, front view. This is a synthetic testing character.','channel'=>'market_image_model:154','quality'=>'1k','ratio'=>'1:1','quantity'=>1],
  'aigc_video'=>['prompt'=>'A synthetic reusable bottle standing still on a plain background, slow gentle camera movement, product test clip.','channel'=>'market_video_model:157','quality'=>'720p','ratio'=>'1:1','duration'=>6,'quantity'=>1,'reference_images'=>[$asset]],
  'aigc_music'=>['prompt'=>'A short calm instrumental melody for a synthetic software test clip.','duration'=>15,'instrumental'=>true],
 ];
 foreach([0,1] as $tenant){$backup[$tenant]=S::table('setting')->where('tenant_id',$tenant)->find();$settings=S::settings($tenant);unset($settings['enabled']);$settings['apps']=array_values(array_unique(array_merge($settings['apps'],array_keys($definitions))));$settings['rpm']=max(120,$settings['rpm']);$values=['enabled'=>1,'config'=>S::encode($settings),'update_time'=>time()];if($backup[$tenant])S::table('setting')->where('tenant_id',$tenant)->update($values);else S::table('setting')->insert($values+['tenant_id'=>$tenant,'create_time'=>time()]);}
 $key=K::create(1,1,['name'=>$run,'daily_limit'=>1000-$spent,'monthly_limit'=>1000-$spent,'scopes'=>array_keys($definitions),'ip_allowlist'=>['127.0.0.1/32'],'expires_at'=>time()+3600]);
 request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];request()->source=app\common\enum\AdminTerminalEnum::USER;
 foreach($definitions as $app=>$params){
  $estimate=R::get($app)['operations']['POST estimate'];$quote=A::call(request(),$estimate+['app_code'=>$app,'route'=>'POST estimate','params'=>[]],M::resolve(1,1,$params));$price=(float)($quote['user_charge_points']??-1);verify($price>0&&$price<=100,'Unexpected per-case quote for '.$app);
  $current=(float)S::table('request')->where('key_id',$key['id'])->sum('actual_user')+(float)S::table('request')->where(['key_id'=>$key['id'],'active'=>1])->sum('budget_user');verify($spent+$current+$price<=1000,'Cumulative budget exceeded');
  $schema=['type'=>'object','required'=>array_keys($params),'properties'=>[],'additionalProperties'=>false];foreach($params as $name=>$value)$schema['properties'][$name]=is_array($value)?['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>['type'=>'string']]:['type'=>is_bool($value)?'boolean':(is_int($value)?'integer':'string')];
  $path='/api/v1/tools/'.$app.'/tasks';$draft=C::save(['code'=>$run.'.'.$app,'kind'=>'tool','method'=>'POST','path'=>$path,'config'=>['title'=>'Local multimodal acceptance','role'=>'submit','input'=>$schema,'example'=>$params,'responses'=>['200'=>['description'=>'Native accepted task','content'=>['application/json'=>['schema'=>['type'=>'object','required'=>['request_id','data'],'properties'=>['request_id'=>['type'=>'string'],'data'=>['type'=>'object','required'=>['task_id','status']]]]]]]]]]);$contracts[]=$draft['id'];$ticket=Preview::issue($draft['id'],$key['id']);
  $wire=$call('POST',$path,$params,$ticket);$row=S::owner('request',1,1)->where(['key_id'=>$key['id'],'idempotency_hash'=>hash('sha256','POST '.$path.' '.$ticket['idempotency_key'])])->find();verify($row,'Submission rejected before tracked admission (HTTP '.$wire['status'].')');$task=$wire['data']['data']['task_id']??'';
  $report['cases'][$app]=['request_id'=>$row['request_id'],'contract_id'=>$draft['id'],'task_id'=>$task,'quote'=>$price,'status'=>$row['status'],'billing_status'=>$row['billing_status'],'charged'=>(float)$row['actual_user'],'pending_budget'=>(float)$row['budget_user']];$save();verify($wire['status']===200&&$task!=='','Submission failed for '.$app.' ('.$row['status'].')');
  $replay=$call('POST',$path,$params,$ticket);verify($replay['body']===$wire['body']&&$replay['status']===$wire['status'],'Idempotent response changed');$report['cases'][$app]['submission_replay']='identical';echo S::encode(['stage'=>'submitted','app'=>$app]+$report['cases'][$app]),"\n";
  $deadline=time()+900;$polls=0;
  do{
   $read=$call('GET','/api/v1/tools/'.$app.'/tasks/'.$task);verify($read['status']===200,'Owned task query failed');$data=$read['data']['data']??[];$polls++;
   $row=S::owner('request',1,1)->where('request_id',$row['request_id'])->find();app\common\service\open_platform\OpenRecovery::refresh($row);$row=S::owner('request',1,1)->where('request_id',$row['request_id'])->find();
   $report['cases'][$app]+= ['query_no_charge'=>true];$report['cases'][$app]=array_replace($report['cases'][$app],['status'=>$row['status'],'billing_status'=>$row['billing_status'],'charged'=>(float)$row['actual_user'],'pending_budget'=>(float)$row['budget_user'],'result_count'=>count($data['results']??[]),'polls'=>$polls]);$save();
   $terminal=in_array($row['status'],['success','completed','failed','canceled','cancelled','partial_failed','pending_confirmation'],true);if($polls===1||$polls%6===0||$terminal)echo S::encode(['stage'=>'poll','app'=>$app]+$report['cases'][$app]),"\n";
   if(!$terminal&&time()<$deadline)sleep(5);
  }while(!$terminal&&time()<$deadline);
  verify($row['status']==='success'&&$report['cases'][$app]['result_count']>0,'Known successful results missing for '.$app);verify($row['billing_status']==='settled'&&(float)$row['budget_user']===0.,'Settlement incomplete for '.$app);verify((float)$row['actual_user']===$price,'Quote and settled retail disagree for '.$app);
  $verified=C::detail($draft['id']);$verified['config']['verification']=['request_id'=>$row['request_id']];$proof=V::assertReceipt($verified);$report['cases'][$app]['publication_proof']='exact_example_and_independent_ledgers';$report['cases'][$app]['contract_digest']=$proof['contract_digest'];$save();
 }
 verify((float)S::table('request')->where(['key_id'=>$key['id'],'method'=>'GET'])->sum('actual_user')===0.,'Free task queries charged');$report['result']='passed';
}catch(Throwable $error){$report['result']='failed';$report['failure']=$error->getMessage();}
finally{
 if($key){S::table('key')->where('id',$key['id'])->update(['status'=>-1,'update_time'=>time()]);$report['charged']=(float)S::table('request')->where('key_id',$key['id'])->sum('actual_user');$report['pending_budget']=(float)S::table('request')->where(['key_id'=>$key['id'],'active'=>1])->sum('budget_user');$report['cumulative_spent']=$spent+$report['charged']+$report['pending_budget'];}
 foreach($contracts as $id)S::table('contract')->where('id',$id)->update(['status'=>'archived','update_time'=>time()]);foreach($backup as $tenant=>$row){if($row)S::table('setting')->where('tenant_id',$tenant)->update(['enabled'=>$row['enabled'],'config'=>$row['config'],'update_time'=>time()]);else S::table('setting')->where('tenant_id',$tenant)->delete();}$report['settings_restored']=true;$report['key_revoked']=true;$save();
}
echo S::encode($report),"\n";exit(($report['result']??'failed')==='passed'?0:1);
