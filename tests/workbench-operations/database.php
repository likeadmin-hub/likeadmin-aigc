<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\workbench\OperationsDashboard as D;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function metric($d,$key){return array_column($d['metrics'],null,'key')[$key];}
$config=config('database.connections.mysql');
if(!in_array($config['hostname'],['127.0.0.1','localhost'],true)||$config['database']!=='z_cn'||$config['prefix']!=='la_')throw new RuntimeException('Local z_cn fixture only');
$tenant=987654310;$other=$tenant+1;$user=987654310;
$now=strtotime('2025-02-03 12:30:00 +0800');$at=$now-3600;$params=['range'=>'today'];
// Connection-local shadow tables test the confirmed virtual source without migrating local data.
$install=file_get_contents(getcwd().'/public/install/db/like.sql');
foreach (['virtual_pay_intent','virtual_pay_receipt'] as $table) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `la_'.$table.'`.*?;/s',$install,$match)) throw new RuntimeException('Missing virtual payment fixture schema');
    Db::execute(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$match[0]));
}
$baseline=D::build(0,$params,$now);
Db::startTrans();
try {
    foreach ([$tenant,$other] as $id) Db::name('tenant')->insert(['id'=>$id,'sn'=>'opfixture'.$id,'name'=>'Operations fixture','point_balance'=>11,'create_time'=>$at,'tactics'=>0]);
    foreach ([$tenant,$other] as $i=>$id) Db::name('user')->insert(['id'=>$user+$i,'tenant_id'=>$id,'sn'=>$user+$i,'account'=>'operations'.$id,'nickname'=>'Fixture','user_money'=>22,'create_time'=>$at]);
    $task=function($tenant,$user,$status,$stamp,$parent=0){return Db::name('ai_app_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>bin2hex(random_bytes(12)),'idempotency_key'=>bin2hex(random_bytes(12)),'app_code'=>'operations_fixture','parent_task_id'=>$parent,'status'=>$status,'create_time'=>$stamp]);};
    $id=$task($tenant,$user,'success',$at);$task($tenant,$user,'failed',$at);$task($tenant,$user,'running',$at);$task($tenant,$user,'success',$at,$id);
    $task($other,$user+1,'success',$at);$task($tenant,$user,'success',$at-86400);
    foreach (['settled','reserved','refunded'] as $status) Db::name('ai_consumption_log')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'consume_no'=>bin2hex(random_bytes(12)),'app_code'=>'operations_fixture','billing_status'=>$status,'actual_tenant_cost'=>5,'actual_user_price'=>7,'create_time'=>$at-86400,'finish_time'=>$at]);
    Db::name('ai_consumption_log')->insert(['tenant_id'=>$other,'user_id'=>$user+1,'consume_no'=>bin2hex(random_bytes(12)),'billing_status'=>'settled','actual_tenant_cost'=>500,'actual_user_price'=>700,'finish_time'=>$at]);
    $order=Db::name('recharge_order')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'sn'=>'operations-paid','pay_status'=>1,'pay_way'=>2,'order_amount'=>10,'pay_time'=>$at,'create_time'=>$at-86400,'delete_time'=>$at]);
    Db::name('recharge_order')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'sn'=>'operations-balance','pay_status'=>1,'pay_way'=>1,'order_amount'=>999,'pay_time'=>$at]);
    Db::name('recharge_order')->insert(['tenant_id'=>$other,'user_id'=>$user+1,'sn'=>'operations-foreign','pay_status'=>1,'pay_way'=>2,'order_amount'=>999,'pay_time'=>$at]);
    $member=Db::name('membership_order')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'order_sn'=>'operations-member','pay_status'=>1,'pay_way'=>3,'order_amount'=>20,'pay_time'=>$at]);
    $foreign=Db::name('membership_order')->insertGetId(['tenant_id'=>$other,'user_id'=>$user+1,'order_sn'=>'operations-foreign-member','pay_status'=>1,'pay_way'=>3,'order_amount'=>999,'pay_time'=>$at]);
    Db::name('refund_record')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'order_id'=>$order,'order_sn'=>'operations-paid','order_type'=>'recharge','refund_amount'=>3,'refund_status'=>1,'update_time'=>$at]);
    Db::name('refund_record')->insert(['tenant_id'=>$other,'user_id'=>$user+1,'order_id'=>$foreign,'order_sn'=>'operations-foreign-member','order_type'=>'membership','refund_amount'=>999,'refund_status'=>1,'update_time'=>$at]);
    foreach ([[200,1,10],[205,1,20],[102,2,7],[204,1,2],[101,2,8]] as [$type,$action,$amount]) Db::name('user_account_log')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'change_object'=>1,'change_type'=>$type,'action'=>$action,'change_amount'=>$amount,'create_time'=>$at]);
    $data=D::build($tenant,$params,$now);
    check($data['status']==='ready',json_encode($data['sources']));
    check(metric($data,'task_total')['value']==3 && metric($data,'active_users')['value']==1,'top-level task count and distinct active users');
    check(metric($data,'task_success_rate')['value']==50,'terminal-only success denominator');
    check(metric($data,'task_total')['previous']==1,'previous equal elapsed window');
    check(metric($data,'cash_received')['value']==30 && metric($data,'paid_orders')['value']==2,'pay-time cash only, deleted financial orders retained, foreign tenant excluded');
    check(metric($data,'order_average')['value']==15 && metric($data,'order_average')['previous']===null,'average uses the same real paid-order scope; no previous orders is undefined');
    check(metric(D::build($tenant,['range'=>'custom','start_date'=>'2025-01-01','end_date'=>'2025-01-01'],$now),'order_average')['value']===null,'no paid orders must not create a zero average');
    check(metric($data,'refund_amount')['value']==3,'refunds scoped across OR order branches');
    check(metric($data,'settled_tenant_points')['value']==5 && metric($data,'settled_user_points')['value']==7,'settlement source truth by finish time');
    check(metric($data,'points_issued')['value']==30 && metric($data,'points_consumed')['value']==7 && metric($data,'points_returned')['value']==2,'point issuance, usage, returns separate');
    $trend=array_column($data['trend']['series'],null,'key')['task_total']['data'];check(count($trend)===13&&array_sum($trend)==3,'hour buckets filled zero');
    $platform=D::build(0,$params,$now);check(metric($platform,'task_total')['value']-metric($baseline,'task_total')['value']==4,'platform count aggregates tenant scope once');
    // Virtual refunds have their own confirmed receipt ledger, not refund_record.
    foreach (['recharge'=>40,'membership'=>60] as $kind=>$amount) {
        $table=$kind==='recharge'?'recharge_order':'membership_order';$sn=$kind==='recharge'?'sn':'order_sn';$tx='operations-virtual-'.$kind;
        $virtualOrder=Db::name($table)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,$sn=>$tx,'transaction_id'=>$tx,'pay_status'=>1,'pay_way'=>4,'order_amount'=>$amount,'pay_time'=>$at]);
        $intent=Db::name('virtual_pay_intent')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$kind,'order_id'=>$virtualOrder,'config_version_id'=>0,'app_id'=>'operations-fixture','env'=>0,'openid'=>'fixture','out_trade_no'=>$tx,'platform'=>'ios','product_id'=>'fixture','amount_cents'=>$amount*100,'attach'=>'fixture','sign_data'=>'{}','status'=>'paid','refund_status'=>'success','refund_cents'=>$amount*100,'wx_order_id'=>$tx,'created_at'=>$at,'expires_at'=>$now,'updated_at'=>$now]);
        foreach ([1,2] as $copy) Db::name('virtual_pay_receipt')->insert(['tenant_id'=>$tenant,'intent_id'=>$intent,'app_id'=>'operations-fixture','env'=>0,'transaction_key'=>$tx.'-refund-'.$copy,'kind'=>'refund','amount_cents'=>$amount*100,'normalized'=>'{}','created_at'=>$at+$copy]);
        // Historical compatibility/refund retries must not double-count this full refund,
        // even if their timestamps would put the same order in a different comparison window.
        foreach ([$at,$at-86400] as $stamp) Db::name('refund_record')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'order_id'=>$virtualOrder,'order_sn'=>$tx,'order_type'=>$kind,'refund_amount'=>$amount,'refund_status'=>1,'update_time'=>$stamp]);
    }
    $virtualData=D::build($tenant,$params,$now);
    check($virtualData['status']==='ready',json_encode($virtualData['sources']));
    check(metric($virtualData,'cash_received')['value']==130,'virtual paid orders included once');
    check(metric($virtualData,'order_average')['value']==32.5,'average weights real order totals across payment sources and excludes point payments');
    check(metric($virtualData,'refund_amount')['value']==103 && metric($virtualData,'refund_amount')['previous']==0,'full virtual refunds merge once per order, traditional duplicate mappings excluded across periods');
    check(array_sum(array_column($virtualData['trend']['series'],null,'key')['refund_amount']['data'])==103,'virtual refunds appear in confirmed-time trend');
    echo "PASS operations virtual recharge/membership full refunds, receipt and traditional mapping deduplication\n";
    // Real split table resolution, same user id as another tenant must never borrow shared data.
    Db::execute('CREATE TEMPORARY TABLE la_user_op_split LIKE la_user');
    Db::execute('CREATE TEMPORARY TABLE la_user_account_log_op_split LIKE la_user_account_log');
    Db::name('tenant')->where('id',$tenant)->update(['tactics'=>1,'sn'=>'op_split']);
    Db::name('user_op_split')->insert(['id'=>$user,'tenant_id'=>$tenant,'sn'=>$user,'account'=>'split','nickname'=>'Split','user_money'=>91,'create_time'=>$at]);
    $split=D::build($tenant,$params,$now);check(metric($split,'user_point_balance')['value']==91&&metric($split,'points_issued')['value']==0,'split users and point logs isolated');
    Db::execute('DROP TEMPORARY TABLE la_user_op_split');Db::execute('DROP TEMPORARY TABLE la_user_account_log_op_split');
    // Missing split table is unavailable, never silently read the shared user table.
    Db::name('tenant')->where('id',$tenant)->update(['sn'=>'op_missing']);
    $missing=D::build($tenant,$params,$now);check($missing['status']==='partial'&&metric($missing,'user_point_balance')['value']===null,'missing source explicit null');
    echo "PASS operations isolation, cash/refunds, ledger settlement, ranking, buckets and split sources\n";
} finally { Db::rollback();Db::execute('DROP TEMPORARY TABLE la_virtual_pay_receipt');Db::execute('DROP TEMPORARY TABLE la_virtual_pay_intent'); }
check(!Db::name('tenant')->where('id',$tenant)->find(),'fixture rollback verified');
