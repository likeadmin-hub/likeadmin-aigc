<?php
/** Targeted cleanup of a local fixture with an independently captured rejection. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenVault as V;
use app\common\service\open_platform\OpenAdmission as A;
use think\facade\Db;
$config=config('database.connections.mysql');if(PHP_SAPI!=='cli'||$config['hostname']!=='127.0.0.1'||$config['database']!=='z_cn')throw new RuntimeException('Local fixture only');
Db::transaction(function()use($argv){
 $id='req_5cc5f14d4e554359a8e7bf67b8ca7667';$proof='req_148707c19734728cb620da61a4f267b4';
 $r=S::owner('request',1,1)->where('request_id',$id)->lock(true)->find();$p=S::owner('request',1,1)->where('request_id',$proof)->find();
 if(!$r||!$p||$r['path']!==$p['path']||$r['payload_hash']!==$p['payload_hash'])throw new RuntimeException('Sibling fixture identity mismatch');
 $s=S::json($p['snapshot']);$wire=S::json(V::open($s['provider_error_blob']??''));$body=S::json($wire['body']??'');
 if(empty($s['provider_rejection_confirmed'])||($body['code']??1)!==0||($body['msg']??'')!=='已关闭，联系管理员')throw new RuntimeException('Closed-channel rejection evidence mismatch');
 $ids=S::json($r['consumption_ids']);if($ids!==[164]||$r['actual_user']!=0||$r['actual_tenant']!=0||$r['tool_task_id']!=='')throw new RuntimeException('Observed fixture changed');
 $c=Db::name('ai_consumption_log')->where(['id'=>164,'tenant_id'=>1,'user_id'=>1])->find();
 if(!$c||$c['run_status']!=='failed'||$c['billing_status']!=='refunded'||$c['actual_user_price']!=0||$c['actual_tenant_cost']!=0||$c['upstream_request_id']!==''||$c['upstream_task_id']!=='')throw new RuntimeException('Native terminal state changed');
 $key=S::table('key')->where('id',$r['key_id'])->find();if(!str_starts_with($key['name'],'local_text_')||(int)$key['status']!==-1)throw new RuntimeException('Not a revoked local test key');
 echo S::encode(['request_id'=>$id,'proof_request_id'=>$proof,'debit'=>0,'rejection'=>'closed_source_channel','apply'=>($argv[1]??'')==='--apply']),"\n";
 if(($argv[1]??'')!=='--apply')return;
 $snapshot=S::json($r['snapshot']);$snapshot['provider_rejection_confirmed']=true;$snapshot['rejection_evidence_request_id']=$proof;
 A::finish($id,['status'=>'failed','billing_status'=>'refunded','active'=>0,'snapshot'=>S::encode($snapshot),'error_code'=>'provider_rejected','error_message'=>'本地旧文本渠道已关闭，原业务记录已结束且未扣款','finish_time'=>time(),'next_run_time'=>0]);
 echo "PASS confirmed local rejection released only admission budget; wallets untouched\n";
});
