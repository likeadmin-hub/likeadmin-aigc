<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenGateway as G;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
$wallets=fn()=>[(string)Db::name('user')->where(['id'=>1,'tenant_id'=>1])->value('user_money'),(string)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
Db::startTrans();try{
    foreach([false,true,null] as $billable)foreach(['completed','failed'] as $status){
        $rid='req_free_fixture_'.bin2hex(random_bytes(12));$contract=['status_path'=>'data.status'];if($billable!==null)$contract['billable']=$billable;
        $row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'kind'=>'market','key_id'=>0,'method'=>'POST','path'=>'/api/v1/tasks','status'=>'running','billing_status'=>$billable===false?'none':'pending','active'=>1,'budget_user'=>0,'budget_tenant'=>0,'actual_user'=>0,'actual_tenant'=>0,'snapshot'=>S::encode(['contract'=>$contract]),'consumption_ids'=>'[]','response_blob'=>'','payload_hash'=>hash('sha256',$rid),'next_run_time'=>time()+60]+S::timestamp();
        $row['id']=S::table('request')->insertGetId($row);G::observeTask($row,['data'=>['status'=>$status]]);$current=S::table('request')->where('id',$row['id'])->find();
        if($billable===false){check((int)$current['active']===0&&$current['billing_status']==='none'&&$current['status']===($status==='failed'?'failed':'success')&&(int)$current['next_run_time']===0&&(int)$current['finish_time']>0,'Free terminal receipt still needs nonexistent settlement');G::observeTask($row,['data'=>['status'=>$status]]);}
        else check((int)$current['active']===1&&$current['billing_status']==='pending','Missing paid or unclassified bill was silently settled');
        check(Db::name('ai_consumption_log')->where('consume_no',$rid)->count()===0,'Free query created a consumption');
    }
    check($wallets()===$before,'Free completion changed wallets');
    echo "PASS explicitly free asynchronous success/failure closes without billing; paid or unclassified missing consumption stays unresolved; repeated query creates no charge\n";
}finally{Db::rollback();}
