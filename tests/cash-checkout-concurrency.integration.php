<?php
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\promotion\PackagePricingService as Price;
use app\common\logic\PayNotifyLogic;

if (($argv[1]??'')==='child') {
    $input=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
    request()->tenantId=$input['tenant'];request()->source=app\common\enum\AdminTerminalEnum::USER;
    if ($input['action']==='create') $result=Price::create($input['tenant'],$input['user'],4,$input['from']??'recharge',$input['package'],'package',$input['quote'],$input['key']);
    else $result=PayNotifyLogic::handle($input['from']??'recharge',$input['sn'],['transaction_id'=>$input['transaction']]);
    echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}

function parallelCash(array $jobs): array {
    $running=[];
    foreach ($jobs as $job) {
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'child',base64_encode(json_encode($job))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        oemCheck(is_resource($process),'child started');fclose($pipes[0]);$running[]=[$process,$pipes];
    }
    $result=[];
    foreach ($running as [$process,$pipes]) {
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        oemCheck(proc_close($process)===0,'child completed: '.$error);$result[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
    return $result;
}

$f=virtualFixture(); // Committed only in the explicitly disposable OEM database.
$quote=Price::quote($f['tenant'],$f['user'],'recharge',$f['package']);
$job=$f+['action'=>'create','quote'=>$quote['quote_hash'],'key'=>'concurrent-purchase-0001'];
$rows=parallelCash([$job,$job,$job,$job]);
oemCheck(count(array_unique(array_column($rows,'order_id')))===1,'four concurrent retries create one order');
$a=$job;$a['key']='concurrent-purchase-0002';$b=$job;$b['key']='concurrent-purchase-0003';
$new=parallelCash([$a,$b]);oemCheck($new[0]['order_id']!==$new[1]['order_id'],'separate concurrent purchases stay distinct');
$orders=[$rows[0],...$new];
Db::name('recharge_order')->whereIn('id',array_column($orders,'order_id'))->update(['pay_way'=>2]);
$settle=$f+['action'=>'settle','sn'=>$orders[0]['order_sn'],'transaction'=>'concurrent-receipt-'.$f['tenant']];
$paid=parallelCash([$settle,$settle,$settle,$settle]);oemCheck(count(array_filter($paid,fn($v)=>$v===true))===4,'duplicate callbacks acknowledge existing settlement');
oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','parallel duplicate receipt grants once');
$a=$settle;$a['sn']=$orders[1]['order_sn'];$b=$settle;$b['sn']=$orders[2]['order_sn'];
$a['transaction']=$b['transaction']='cross-order-receipt-'.$f['tenant'];$paid=parallelCash([$a,$b]);
oemCheck(count(array_filter($paid,fn($v)=>$v===true))===1,'one channel transaction settles only one concurrent order');
oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='200.00','losing duplicate transaction changes no balance');
$plan=app\common\service\membership\MembershipService::savePlan($f['tenant'],['name'=>'Concurrent member','monthly_price'=>'19.00','duration_months'=>1,'features'=>[],'bonus_points'=>'5.00','app_codes'=>[]]);
$q=Price::quote($f['tenant'],$f['user'],'membership',(int)$plan->id);
$member=$f+['action'=>'create','from'=>'membership','quote'=>$q['quote_hash'],'key'=>'concurrent-member-0001'];$member['package']=(int)$plan->id;
$orders=parallelCash([$member,$member,$member]);oemCheck(count(array_unique(array_column($orders,'order_id')))===1,'concurrent member retries create one order');
Db::name('membership_order')->where('id',$orders[0]['order_id'])->update(['pay_way'=>2]);
$memberPaid=$f+['action'=>'settle','from'=>'membership','sn'=>$orders[0]['order_sn'],'transaction'=>'member-receipt-'.$f['tenant']];
parallelCash([$memberPaid,$memberPaid,$memberPaid]);
oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='205.00','concurrent member callbacks grant bonus once');
$expiry=(int)Db::name('user_membership')->where(['tenant_id'=>$f['tenant'],'user_id'=>$f['user']])->value('expire_time');
oemCheck($expiry>time()+27*86400&&$expiry<time()+33*86400,'concurrent member callbacks extend only one month');
echo "PASS real concurrent recharge/member purchases, transport retries, independent orders, duplicate callbacks and cross-order payment arbitration\n";
