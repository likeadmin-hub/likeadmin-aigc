<?php
/** Private pipe fixture for real-sdk-local.py. Never invoke directly or print its output. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenKeyService as K;
use app\common\service\open_platform\OpenMarketBilling as B;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local SDK fixture only');
check(($argv[1]??'')==='--authorized-1000','Explicit cumulative cap required');check(Db::name('user')->where(['tenant_id'=>1,'id'=>1,'account'=>'xhadmin001','is_disable'=>0])->find(),'Authorized local account missing');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$lock=fopen($dir.'/paid.lock','c');check($lock&&flock($lock,LOCK_EX|LOCK_NB),'Acceptance lock busy');
request()->tenantId=1;request()->userId=1;request()->source=app\common\enum\AdminTerminalEnum::USER;
$run='local_text_'.bin2hex(random_bytes(6));$backup=[];$ids=[];$key=null;$report=['run'=>$run,'tenant_id'=>1,'user_id'=>1,'cap'=>1000,'sdk'=>'openai-python','cases'=>[]];
$prior=S::owner('key',1,1)->whereLike('name','local_text_%')->column('id');$spent=$prior?(float)S::table('request')->whereIn('key_id',$prior)->sum('actual_user')+(float)S::table('request')->whereIn('key_id',$prior)->where('active',1)->sum('budget_user'):0.;check($spent<1000,'Cumulative cap exhausted');$report['prior_spent']=$spent;
try{
 foreach([0,1] as $tenant){$backup[$tenant]=S::table('setting')->where('tenant_id',$tenant)->find();$settings=S::settings($tenant);unset($settings['enabled']);$settings['models']=array_values(array_unique(array_merge($settings['models'],['2'])));$settings['rpm']=max(60,$settings['rpm']);$v=['enabled'=>1,'config'=>S::encode($settings),'update_time'=>time()];if($backup[$tenant])S::table('setting')->where('tenant_id',$tenant)->update($v);else S::table('setting')->insert($v+['tenant_id'=>$tenant,'create_time'=>time()]);}
 $key=K::create(1,1,['name'=>$run,'daily_limit'=>1000-$spent,'monthly_limit'=>1000-$spent,'scopes'=>['2'],'ip_allowlist'=>['127.0.0.1/32'],'expires_at'=>time()+600]);
 $schema=['type'=>'object','required'=>['model','messages','max_tokens'],'properties'=>['model'=>['type'=>'string','enum'=>['qwen3.6-plus']],'messages'=>['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>['type'=>'object','required'=>['role','content'],'properties'=>['role'=>['type'=>'string','enum'=>['user']],'content'=>['type'=>'string','maxLength'=>64]]]],'max_tokens'=>['type'=>'integer','minimum'=>1,'maximum'=>16],'stream'=>['type'=>'boolean'],'stream_options'=>['type'=>'object']]];
 $inputs=[];$budget=0.;foreach([false,true] as $stream){$p=['model'=>'qwen3.6-plus','messages'=>[['role'=>'user','content'=>'Reply OK.']],'max_tokens'=>16,'stream'=>$stream];if($stream)$p['stream_options']=['include_usage'=>true];$values=C::validate(['code'=>$run.($stream?'.stream':'.sync'),'method'=>'POST','path'=>'/api/v1/chat/completions','kind'=>'market','product_id'=>2,'config'=>['title'=>'Loopback OpenAI SDK acceptance fixture','executor'=>$stream?'sse':'json','role'=>'submit','model'=>'qwen3.6-plus','when'=>['stream'=>$stream],'billable'=>true,'input'=>$schema,'example'=>$p,'responses'=>['200'=>['description'=>'Upstream protocol']],'billing'=>[['sku_id'=>2,'basis'=>'usage','max_units'=>1024,'usage_path'=>'usage.total_tokens']]]]);$quote=B::quote(1,['kind'=>'market','product_id'=>2]+S::json($values['config']),$p);check($quote['user']>0&&$quote['user']<=10,'Unexpected SDK reservation');$budget+=$quote['user'];$ids[]=S::table('contract')->insertGetId($values+['version'=>1,'status'=>'published']+S::timestamp());$inputs[]=['params'=>$p,'idempotency_key'=>$run.($stream?'-stream':'-sync')];}
 check($spent+$budget<=1000,'SDK batch exceeds remaining cap');
 // stdout is consumed only by the parent Python pipe; no credentials go to reports/logs.
 echo S::encode(['key'=>$key['key'],'cases'=>$inputs]),"\n";flush();
 $done=json_decode((string)fgets(STDIN),true);check(is_array($done)&&isset($done['passed']),'SDK child result missing; do not repeat uncertain submissions');$report['client']=$done;
 foreach($inputs as $input){$row=S::owner('request',1,1)->where(['key_id'=>$key['id'],'idempotency_hash'=>hash('sha256','POST /api/v1/chat/completions '.$input['idempotency_key'])])->find();check($row,'SDK request was not admitted');$report['cases'][]=['request_id'=>$row['request_id'],'stream'=>$input['params']['stream'],'status'=>$row['status'],'billing_status'=>$row['billing_status'],'charged'=>(float)$row['actual_user'],'pending_budget'=>(float)$row['budget_user']];check($row['status']==='success'&&$row['billing_status']==='settled'&&(int)$row['active']===0,'SDK request did not finish with trusted usage');app\common\service\open_platform\OpenVerification::assertLedger($row);}
 check($done['passed'],'SDK client failed protocol validation');$report['result']='passed';
}catch(Throwable $e){$report['result']='failed';$report['failure']=$e->getMessage();}
finally{
 if($key){S::table('key')->where('id',$key['id'])->update(['status'=>-1,'update_time'=>time()]);$report['charged']=(float)S::table('request')->where('key_id',$key['id'])->sum('actual_user');$report['pending_budget']=(float)S::table('request')->where(['key_id'=>$key['id'],'active'=>1])->sum('budget_user');$report['cumulative_spent']=round($spent+$report['charged']+$report['pending_budget'],2);}
 foreach($ids as $id)S::table('contract')->where('id',$id)->update(['status'=>'archived','update_time'=>time()]);foreach($backup as $tenant=>$row){if($row)S::table('setting')->where('tenant_id',$tenant)->update(['enabled'=>$row['enabled'],'config'=>$row['config'],'update_time'=>time()]);else S::table('setting')->where('tenant_id',$tenant)->delete();}$report['key_revoked']=true;$report['settings_restored']=true;file_put_contents($dir.'/'.$run.'.json',S::encode($report));
}
echo S::encode($report),"\n";exit(($report['result']??'')==='passed'?0:1);
