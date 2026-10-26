<?php
$root=getenv('GENERATION_TEST_ROOT') ?: dirname(__DIR__);require $root.'/runtime-loader.php';
if ($source=getenv('GENERATION_TEST_SOURCE')) spl_autoload_register(static function($class) use ($source){$file=rtrim($source,'/').'/'.str_replace('\\','/',$class).'.php';if(is_file($file))require_once $file;},true,true);
(new AigcRuntimeApp($root))->initialize();
use think\facade\Db;
use app\common\model\ai\{AiConsumptionLog as C,AiAppTask as T};
use app\common\service\power\MarketVideoRuntimeService as V;
use app\common\service\point\PointService as P;
$c=config('database.connections.mysql');if(($c['hostname']??'')!=='127.0.0.1'||($c['database']??'')!==(getenv('GENERATION_TEST_DATABASE') ?: 'z_cn'))throw new RuntimeException('Local rollback only');
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$tenant=1;$user=(int)Db::name('user')->where('tenant_id',$tenant)->where('user_money','>',20)->value('id');if(!$user)throw new RuntimeException('Local funded fixture missing');
function state($tenant,$user){return ['tenant'=>(float)Db::name('tenant')->where('id',$tenant)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money'),'tasks'=>Db::name('ai_app_task')->count(),'consumptions'=>Db::name('ai_consumption_log')->count(),'user_logs'=>Db::name('user_account_log')->count(),'tenant_logs'=>Db::name('tenant_point_log')->count()];}
$before=state($tenant,$user);Db::startTrans();
try{
 $sn='AUDIT'.bin2hex(random_bytes(6));$now=time();$t=T::create(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>$sn,'app_code'=>'aigc_video','action_code'=>'generate','status'=>'running','idempotency_key'=>$sn,'create_time'=>$now,'update_time'=>$now]);
 $snapshot=['usage_unit'=>'output_second','usage_unit_size'=>1,'platform_price'=>1,'tenant_price'=>1,'quantity'=>10,'requires_actual_duration'=>true];
 $c=C::create(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$t['id'],'consume_no'=>$sn,'app_code'=>'aigc_video','action_code'=>'generate','resource_type'=>'model','model_code'=>'audit-no-submit','billing_status'=>'reserved','run_status'=>'reserved','reserved_tenant_cost'=>10,'reserved_user_price'=>10,'price_snapshot'=>$snapshot,'quantity'=>10,'usage_unit'=>'output_second','create_time'=>$now,'update_time'=>$now]);
 P::reserveBusinessAmountsInCurrentTransaction($tenant,$user,10,10,$sn.'-reserve','local rollback regression');
 $settle=new ReflectionMethod(V::class,'settle');$videos=[['url'=>'https://fixtures.invalid/completed.mp4']];
 $settle->invoke(null,(int)$c['id'],$videos,'','audit-provider',[]);$current=C::find($c['id'])->toArray();
 check($current['billing_status']==='reserved','missing duration receipt retains original reservation');
 check(state($tenant,$user)['user']===$before['user']-10,'missing receipt does not deduct twice');
 $settle->invoke(null,(int)$c['id'],$videos,'','audit-provider',['usage'=>['output_video_duration'=>5,'total_tokens'=>1000]]);
 $current=C::find($c['id'])->toArray();$after=state($tenant,$user);
 check($current['billing_status']==='settled' && (float)$current['quantity']===5.0,'later measured receipt settles five seconds');
 check($after['user']===$before['user']-5 && $after['tenant']===$before['tenant']-5,'reservation difference refunded to both wallets');
 $settle->invoke(null,(int)$c['id'],$videos,'','audit-provider',['usage'=>['output_video_duration'=>5]]);
 check(state($tenant,$user)===$after,'duplicate receipt leaves balances and ledgers unchanged');
 foreach(['success','failed','canceled'] as $outcome){
  $baseline=state($tenant,$user);$sn='METER'.bin2hex(random_bytes(6));
  $t=T::create(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>$sn,'app_code'=>'aigc_video','action_code'=>'generate','status'=>'running','idempotency_key'=>$sn,'create_time'=>$now,'update_time'=>$now]);
  $snapshot=['usage_unit'=>'business_unit','usage_unit_size'=>1,'platform_price'=>1.1,'tenant_price'=>1.1,'quantity'=>5,'requires_actual_duration'=>true,'billing_meter'=>\app\common\service\power\MarketGenerationMeter::contract(['usage_unit'=>'business_unit','meter_summary'=>'生成秒数 + 参考视频秒数，总点数向上取整']),'meter_context'=>['reference_video_seconds'=>7.25]];
  $c=C::create(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$t['id'],'consume_no'=>$sn,'app_code'=>'aigc_video','action_code'=>'generate','resource_type'=>'model','model_code'=>'audit-no-submit','billing_status'=>'reserved','run_status'=>'reserved','reserved_tenant_cost'=>14,'reserved_user_price'=>14,'price_snapshot'=>$snapshot,'quantity'=>5,'usage_unit'=>'business_unit','create_time'=>$now,'update_time'=>$now]);
  P::reserveBusinessAmountsInCurrentTransaction($tenant,$user,14,14,$sn.'-reserve','local rollback compound meter');
  $complete=static function()use($outcome,$settle,$c,$videos){
   if($outcome==='success')$settle->invoke(null,(int)$c['id'],$videos,'','audit-provider',['usage'=>['output_video_duration'=>4]]);
   elseif($outcome==='canceled')V::cancel((int)$c['id']);
   else V::fail((int)$c['id'],'fixture upstream failure');
  };
  $complete();$actual=state($tenant,$user);$cost=$outcome==='success'?13:0;
  check(round($baseline['user']-$actual['user'],6)===(float)$cost && round($baseline['tenant']-$actual['tenant'],6)===(float)$cost,'compound meter '.$outcome.' preserves both wallet amounts');
  $complete();check(state($tenant,$user)===$actual,'compound meter '.$outcome.' callback is idempotent');
 }
}finally{Db::rollback();check(state($tenant,$user)===$before,'all test records and balances rolled back');}
