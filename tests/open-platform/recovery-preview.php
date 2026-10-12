<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
Db::startTrans();try{
    $base=['tenant_id'=>123456,'user_id'=>456789,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','tool_task_id'=>'123','status'=>'running','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>S::encode(['native_selection_blob'=>'private frozen channel','provider_error_blob'=>'private receipt','quote'=>['prompt'=>'private input']]),'payload_hash'=>hash('sha256','fixture'),'response_blob'=>'private output','budget_user'=>65,'budget_tenant'=>5,'actual_user'=>0,'lease_expire'=>0]+S::timestamp();
    foreach([[],['kind'=>'market','app_code'=>'','tool_task_id'=>'','status'=>'pending_confirmation'],['budget_user'=>0,'actual_user'=>5,'billing_status'=>'settlement_failed','lease_expire'=>time()+60],['budget_user'=>0,'actual_user'=>5,'billing_status'=>'settled','active'=>0,'status'=>'success']] as $override){
        $rid='req_preview_fixture_'.bin2hex(random_bytes(12));S::table('request')->insert(array_replace($base,$override,['request_id'=>$rid]));
        $before=S::table('request')->where('request_id',$rid)->find();$preview=M::recoveryPreview(123456,$rid);$after=S::table('request')->where('request_id',$rid)->find();
        check($before===$after&&$preview['will_modify']===false&&$preview['will_query']===false&&$preview['will_charge']===false,'Diagnosis executed recovery or mutated receipt');
        check($preview['scope']===['requests'=>1,'tenant_id'=>123456,'user_id'=>456789],'Preview scope unclear');
        check(!str_contains(S::encode($preview),'private ')&&!isset($preview['preview']['snapshot']),'Protected snapshot or provider receipt exposed');
        check($preview['tracking']['query_available']===($before['tool_task_id']!==''),'Known task tracking capability incorrect');
        check($preview['tracking']['manual_review_required']===((int)$before['active']===1&&$before['tool_task_id']===''),'Unknown acceptance promised automatic retry');
        check($preview['resume_impact']['may_charge_existing_request']===((int)$before['active']===1&&(float)$before['budget_user']>0),'Possible admitted charge concealed');
        check($preview['resume_impact']['may_refund_existing_request']===((int)$before['active']===1&&$before['billing_status']==='settlement_failed'),'Existing charge/refund impact wrong');
        check($preview['tracking']['resubmission_allowed']===false&&$preview['resume_impact']['historical_backcharge_allowed']===false,'Preview allowed unknown resubmission or historical charge');
        $denied=false;try{M::recoveryPreview(123457,$rid);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Cross-tenant diagnosis leaked');
    }
    echo "PASS diagnosis never queries/writes/charges; admitted charge/refund, unknown acceptance and lease impacts shown without protected snapshots; tenant isolation\n";
}finally{Db::rollback();}
