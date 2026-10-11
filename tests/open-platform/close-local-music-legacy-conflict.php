<?php
/** Close only the known refunded local acceptance failure. Never changes a wallet or back-charges. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenStore as S,OpenCallContext as Context,OpenAdmission,OpenVerification};
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$config=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$config['hostname']==='127.0.0.1'&&$config['database']==='z_cn','Local test only');
check(($argv[1]??'')==='--apply-known-refunded-test','Explicit local test correction required');
$rid='req_3181802ef88308ef07488dbd57aad202';
$wallets=static fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
Db::transaction(static function()use($rid){
    $r=S::owner('request',1,1)->where(['request_id'=>$rid,'tool_task_id'=>'328'])->lock(true)->find();check($r&&S::json($r['consumption_ids'])===[704],'Known local request identity changed');
    $key=S::owner('key',1,1)->where(['id'=>$r['key_id'],'name'=>'local_text_a90d7e5ecf7e','status'=>-1])->find();check($key,'Not the revoked acceptance key');
    $c=Db::name('ai_consumption_log')->where(['id'=>704,'tenant_id'=>1,'user_id'=>1])->lock(true)->find();$p=S::json($c['price_snapshot']);check(($p['runtime_adapter']??'')==='music_operation'&&($p['api_code']??'')==='wav'&&(float)$c['actual_user_price']===0.,'Not the zero-cost legacy conflict');
    foreach(['user_account_log','tenant_point_log'] as $table)foreach(['reserve'=>2,'refund'=>1] as $suffix=>$action){$q=Db::name($table)->where(['tenant_id'=>1,'source_sn'=>$c['consume_no'].'-'.$suffix,'action'=>$action]);if($table==='user_account_log')$q->where('user_id',1);check($q->count()===1&&(float)$q->sum('change_amount')===14.,'Independent complete refund evidence missing');}
    if($r['status']==='failed'&&$r['billing_status']==='refunded'){OpenVerification::assertLedger($r);return;}
    check($c['run_status']==='success'&&$c['billing_status']==='settled','Conflicting receipt changed');$native=Db::name('aigc_music_task')->where(['id'=>328,'tenant_id'=>1,'user_id'=>1,'consumption_id'=>704])->lock(true)->find();check($native&&$native['status']==='running'&&$native['billing_status']==='reserved','Native state changed');
    $now=time();$error='本地验收发现旧版任务处理冲突；原预占已退回，此任务不作为成功导出';
    Db::name('aigc_music_task')->where('id',328)->update(['status'=>'failed','billing_status'=>'refunded','error_code'=>'legacy_runtime_conflict','error'=>$error,'finish_time'=>$now,'update_time'=>$now]);
    Db::name('ai_consumption_log')->where('id',704)->update(['run_status'=>'failed','billing_status'=>'refunded','error_code'=>'legacy_runtime_conflict','error_message'=>$error,'finish_time'=>$now,'update_time'=>$now]);
    Db::name('ai_app_task')->where(['id'=>$c['app_task_id'],'business_table'=>'aigc_music_task','business_id'=>328])->update(['status'=>'failed','finish_time'=>$now,'update_time'=>$now]);
    Context::enter($rid);try{Context::observePoints(1,1,14.,14.,$c['consume_no'].'-refund',['consumption_id'=>704],true);OpenAdmission::finish($rid,['status'=>'failed','billing_status'=>'refunded','active'=>0,'error_code'=>'legacy_runtime_conflict','error_message'=>$error,'next_run_time'=>0,'finish_time'=>$now,'lease_expire'=>0,'lease_token'=>'']);}finally{Context::leave();}
    // The legacy processing job has no valid export to persist; prevent endless retries.
    Db::name('ai_task_job')->where(['consumption_id'=>704,'job_type'=>'process_result'])->whereIn('status',['pending','retrying'])->update(['status'=>'success','last_error'=>'','finish_time'=>$now,'update_time'=>$now]);
    OpenVerification::assertLedger(S::owner('request',1,1)->where('request_id',$rid)->find());
});
check($wallets()===$before,'Correction changed a wallet');echo "PASS known local WAV conflict closed refunded, complete independent ledger linked; no wallet mutation, back-charge or resubmission\n";
