<?php
/** Authorized loopback-only acceptance of native async/composite tools. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenKeyService as K;
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');verify(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local DB only');
verify(($argv[1]??'')==='--authorized-1000','Explicit paid acceptance cap required');
verify(Db::name('user')->where(['id'=>1,'tenant_id'=>1,'account'=>'xhadmin001','is_disable'=>0])->find(),'Authorized local identity missing');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$lock=fopen($dir.'/paid.lock','c');verify($lock&&flock($lock,LOCK_EX|LOCK_NB),'Another paid acceptance is running');
$run='local_text_'.bin2hex(random_bytes(6));$key=null;$backup=[];$contracts=[];$cases=[];$report=['run'=>$run,'cap'=>1000,'tenant_id'=>1,'user_id'=>1,'cases'=>[]];
$prior=S::owner('key',1,1)->whereLike('name','local_text_%')->column('id');$priorSpent=$prior?(float)S::table('request')->whereIn('key_id',$prior)->sum('actual_user')+(float)S::table('request')->whereIn('key_id',$prior)->where('active',1)->sum('budget_user'):0.;verify($priorSpent<1000,'Cumulative cap exhausted');$report['prior_spent']=$priorSpent;
$save=function()use(&$report,$dir,$run){file_put_contents($dir.'/'.$run.'.json',S::encode($report));};
$call=function(string $method,string $path,array $params=[],string $idem='',?string $file=null)use(&$key){
 $h=curl_init('http://127.0.0.1:19874'.$path);$headers=['Authorization: Bearer '.$key['key']];if($idem!=='')$headers[]='Idempotency-Key: '.$idem;
 if($method==='GET'&&$params)curl_setopt($h,CURLOPT_URL,'http://127.0.0.1:19874'.$path.'?'.http_build_query($params));
 if($method==='POST'){curl_setopt($h,CURLOPT_POST,true);if($file){$params['file']=new CURLFile($file);curl_setopt($h,CURLOPT_POSTFIELDS,$params);}else{$headers[]='Content-Type: application/json';curl_setopt($h,CURLOPT_POSTFIELDS,S::encode($params));}}
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>$headers]);$body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$errno=curl_errno($h);curl_close($h);verify($errno===0,'Preview connection failed; never resubmit unknown acceptance');return ['status'=>$status,'body'=>$body,'data'=>S::json((string)$body)];
};
$publish=function(string $app,string $route,array $schema,array $example=[])use(&$contracts,$run){[$method,$suffix]=explode(' ',$route,2);$values=C::validate(['code'=>$run.'.'.$app.'.'.count($contracts),'method'=>$method,'path'=>'/api/v1/tools/'.$app.'/'.$suffix,'kind'=>'tool','config'=>['title'=>'Local native tool acceptance','role'=>$route==='POST tasks'?'submit':'resource','input'=>$schema,'example'=>$example,'responses'=>['200'=>['description'=>'Native tool response']]]]);$contracts[]=S::table('contract')->insertGetId($values+['version'=>1,'status'=>'published']+S::timestamp());};
try{
 foreach([0,1] as $tenant){$backup[$tenant]=S::table('setting')->where('tenant_id',$tenant)->find();$settings=S::settings($tenant);unset($settings['enabled']);$settings['apps']=array_values(array_unique(array_merge($settings['apps'],['aigc_product_suite','smart_clip'])));$settings['rpm']=max(120,$settings['rpm']);$v=['enabled'=>1,'config'=>S::encode($settings),'update_time'=>time()];if($backup[$tenant])S::table('setting')->where('tenant_id',$tenant)->update($v);else S::table('setting')->insert($v+['tenant_id'=>$tenant,'create_time'=>time()]);}
 $key=K::create(1,1,['name'=>$run,'daily_limit'=>1000-$priorSpent,'monthly_limit'=>1000-$priorSpent,'scopes'=>['assets','aigc_product_suite','smart_clip'],'ip_allowlist'=>['127.0.0.1/32'],'expires_at'=>time()+3600]);
 // Temporary contracts are scoped to a loopback key and archived in finally.
 $base=['type'=>'object','additionalProperties'=>true];
 foreach(['aigc_product_suite','smart_clip'] as $app)$publish($app,'POST estimate',$base);
 $publish('smart_clip','GET template/lists',$base);$publish('smart_clip','GET template/detail',$base);
 $assets=[];foreach(['image'=>'test-product.png','video'=>'test-clip.mp4'] as $type=>$name){$wire=$call('POST','/api/v1/assets',['type'=>$type],$run.'-asset-'.$type,$dir.'/fixtures/'.$name);verify($wire['status']===200&&!empty($wire['data']['data']['file_id']),'Synthetic '.$type.' upload failed (HTTP '.$wire['status'].')');$asset=$wire['data']['data'];$owned=$call('GET','/api/v1/assets/'.$asset['file_id']);verify($owned['status']===200&&$owned['data']['data']['file_id']===$asset['file_id'],'Own uploaded asset inaccessible');$assets[$type]=$asset['file_id'];$report['assets'][$type]=['file_id'=>$asset['file_id'],'size'=>$asset['size'],'mime'=>$asset['mime']];$save();echo S::encode(['stage'=>'asset_uploaded','type'=>$type,'size'=>$asset['size']]),"\n";}
 $templates=$call('GET','/api/v1/tools/smart_clip/template/lists',['api'=>'realman_broadcast','page_size'=>5]);verify($templates['status']===200,'Template list failed');$styles=$templates['data']['data']['results']??[];verify($styles!==[],'No available realman templates');$style=(string)$styles[0]['id'];
 $definitions=[
  'aigc_product_suite'=>['product_images'=>[$assets['image']],'module_codes'=>['hero','detail'],'prompt'=>'A plain reusable bottle. Preserve shape and color. Clean product photography.','language'=>'none','ratio'=>'1:1'],
  'smart_clip'=>['api'=>'realman_broadcast','styleId'=>$style,'videoUrl'=>$assets['video'],'duration'=>6]
 ];
 foreach($definitions as $app=>$params){
  $schema=['type'=>'object','required'=>array_keys($params),'properties'=>[],'additionalProperties'=>false];foreach($params as $field=>$value)$schema['properties'][$field]=is_array($value)?['type'=>'array','minItems'=>1,'maxItems'=>3,'items'=>['type'=>'string']]:['type'=>is_int($value)?'integer':'string'];$publish($app,'POST tasks',$schema,$params);
  $quote=$call('POST','/api/v1/tools/'.$app.'/estimate',$params);verify($quote['status']===200,'Native estimate failed for '.$app);$price=(float)($quote['data']['data']['user_charge_points']??-1);verify($price>=0&&$price<=100,'Unexpected per-case retail quote');$used=(float)S::table('request')->where('key_id',$key['id'])->sum('actual_user')+(float)S::table('request')->where(['key_id'=>$key['id'],'active'=>1])->sum('budget_user');verify($priorSpent+$used+$price<=1000,'Cumulative budget exceeded');
  $path='/api/v1/tools/'.$app.'/tasks';$idem=$run.'-'.$app;$wire=$call('POST',$path,$params,$idem);$row=S::owner('request',1,1)->where(['key_id'=>$key['id'],'idempotency_hash'=>hash('sha256','POST '.$path.' '.$idem)])->find();verify($row,'Submission rejected before admission');$task=$wire['data']['data']['task_id']??null;verify($wire['status']===200&&$task,'Native task ID missing for '.$app.' ('.$row['status'].')');
  $replay=$call('POST',$path,$params,$idem);verify($replay['status']===$wire['status']&&$replay['body']===$wire['body'],'Idempotent submission changed');
  $cases[$app]=['request_id'=>$row['request_id'],'task_id'=>$task,'status'=>$row['status'],'quote'=>$price,'submission_replay'=>'identical','polls'=>0];$report['cases']=$cases;$save();echo S::encode(['stage'=>'submitted','app'=>$app]+$cases[$app]),"\n";
 }
 $deadline=time()+720;
 do{
  $waiting=false;foreach($cases as $app=>&$case){if(in_array($case['status'],['success','completed','failed','canceled','cancelled','partial_failed','pending_confirmation'],true))continue;
   $wire=$call('GET','/api/v1/tools/'.$app.'/tasks/'.$case['task_id']);verify($wire['status']===200,'Own native task query failed');$data=$wire['data']['data']??[];$case['status']=(string)($data['status']??'running');$case['polls']++;$case['result_count']=count($data['results']??[]);
   $row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();app\common\service\open_platform\OpenRecovery::refresh($row);$row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();$case['billing_status']=$row['billing_status'];$case['charged']=(float)$row['actual_user'];$case['pending_budget']=(float)$row['budget_user'];$case['consumption_ids']=S::json($row['consumption_ids']);
   if(!in_array($case['status'],['success','completed','failed','canceled','cancelled','partial_failed','pending_confirmation'],true))$waiting=true;
   if($case['polls']===1||$case['polls']%6===0||!$waiting)echo S::encode(['stage'=>'poll','app'=>$app]+$case),"\n";
  }unset($case);$report['cases']=$cases;$save();if($waiting&&time()<$deadline)sleep(5);
 }while($waiting&&time()<$deadline);
 foreach($cases as $app=>&$case){verify($case['status']==='success'&&$case['result_count']>0,'Native result incomplete for '.$app.' ('.$case['status'].')');verify($case['billing_status']==='settled'&&$case['pending_budget']===0.,'Native bill unsettled for '.$app);verify($case['charged']===$case['quote'],'Native quote/actual retail mismatch');
  $sources=[];foreach($case['consumption_ids'] as $id){$c=Db::name('ai_consumption_log')->where(['id'=>$id,'tenant_id'=>1,'user_id'=>1])->find();verify($c&&$c['billing_status']==='settled','Composite child bill unsettled');$sources[]=$c['consume_no'];$sources[]=$c['consume_no'].'-reserve';}
  if($app==='smart_clip'){$native=(int)app\common\service\open_platform\OpenToolTasks::decode($app,$case['task_id']);$bill=Db::name('smart_clip_billing')->where(['task_id'=>$native,'tenant_id'=>1,'user_id'=>1])->find();verify($bill&&$bill['billing_status']==='deducted','Native SmartClip billing missing');$sources[]=$bill['user_point_sn'];}
  $net=function($table,$extra=[])use($sources){$logs=Db::name($table)->where(['tenant_id'=>1]+$extra)->whereIn('source_sn',$sources)->select()->toArray();$total=0.;foreach($logs as $l)$total+=((int)$l['action']===2?1:-1)*(float)$l['change_amount'];return round($total,2);};$row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();verify($net('user_account_log',['user_id'=>1])===$case['charged'],'Independent user ledger mismatch');verify($net('tenant_point_log')===(float)$row['actual_tenant'],'Independent tenant ledger mismatch');$case['independent_ledgers']='matched';
 }unset($case);$report['cases']=$cases;$report['result']='passed';
}catch(Throwable $e){$report['result']='failed';$report['failure']=$e->getMessage();}
finally{
 if($key){S::table('key')->where('id',$key['id'])->update(['status'=>-1,'update_time'=>time()]);$report['charged']=(float)S::table('request')->where('key_id',$key['id'])->sum('actual_user');$report['pending_budget']=(float)S::table('request')->where(['key_id'=>$key['id'],'active'=>1])->sum('budget_user');$report['cumulative_spent']=$priorSpent+$report['charged']+$report['pending_budget'];}
 foreach($contracts as $id)S::table('contract')->where('id',$id)->update(['status'=>'archived','update_time'=>time()]);foreach($backup as $t=>$row){if($row)S::table('setting')->where('tenant_id',$t)->update(['enabled'=>$row['enabled'],'config'=>$row['config'],'update_time'=>time()]);else S::table('setting')->where('tenant_id',$t)->delete();}$report['key_revoked']=true;$report['settings_restored']=true;$save();
}echo S::encode($report),"\n";exit(($report['result']??'failed')==='passed'?0:1);
