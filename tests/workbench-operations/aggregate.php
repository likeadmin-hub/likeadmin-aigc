<?php
/** Connection-local aggregation fixtures; no persistent tenant/ledger writes. */
require dirname(__DIR__,2).'/runtime-loader.php';
(new AigcRuntimeApp(getcwd()))->initialize();

use think\facade\Db;
use app\common\service\workbench\OperationsDashboard;
use app\common\service\workbench\OperationsRange;

function aggregateCheck(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$c=config('database.connections.mysql');
aggregateCheck(($c['hostname']??'')==='127.0.0.1' && ($c['database']??'')==='z_cn' && Db::query('SELECT DATABASE() db')[0]['db']==='z_cn','Verified local development database required');
Db::execute('CREATE TEMPORARY TABLE operations_aggregate_fixture (tenant_id INT NOT NULL,user_id INT NOT NULL,created_at INT NOT NULL,amount DECIMAL(12,2) NOT NULL)');
$originalMode=Db::query('SELECT @@SESSION.sql_mode mode')[0]['mode'];
Db::execute('SET SESSION sql_mode=?',[$originalMode.',ONLY_FULL_GROUP_BY']);
$stamp=fn(string $date)=>strtotime($date.' +0800');
try {
    $rows=[
        [1,11,'2025-02-01 01:00:00',2.50],
        [1,11,'2025-02-02 01:00:00',3.50],
        [1,12,'2025-02-02 02:00:00',4.25],
        [2,11,'2025-02-02 01:00:00',999],
        [1,11,'2025-01-25 01:00:00',100],
        [1,12,'2025-01-31 12:30:01',999],
        [1,12,'2025-02-07 12:30:01',999],
    ];
    foreach ($rows as [$tenant,$user,$at,$amount]) Db::table('operations_aggregate_fixture')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'created_at'=>$stamp($at),'amount'=>$amount]);
    $range=OperationsRange::resolve(['range'=>'7d'],$stamp('2025-02-07 12:30:00'));
    $dashboard=(new ReflectionClass(OperationsDashboard::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($dashboard,'range'))->setValue($dashboard,$range);
    $sql=[];
    Db::listen(function($query)use(&$sql){if(str_starts_with($query,'SELECT')&&str_contains($query,'operations_aggregate_fixture'))$sql[]=$query;});
    [$current,$previous,$buckets]=(new ReflectionMethod($dashboard,'values'))->invoke($dashboard,
        Db::table('operations_aggregate_fixture')->where('tenant_id',1),'created_at',[
            'total'=>'COUNT(*)','amount'=>'SUM(amount)',
            'active'=>'COUNT(DISTINCT CONCAT(tenant_id,\':\',user_id))',
        ]);
    aggregateCheck((int)$current['total']===3 && (float)$current['amount']===10.25,'Current totals exclude another tenant and the upper boundary');
    aggregateCheck((int)$current['active']===2,'A user active on two days counts once in the period total');
    aggregateCheck((int)$previous['total']===1 && (float)$previous['amount']===100.0,'Previous window retains equal elapsed duration and exclusive upper boundary');
    aggregateCheck(count($buckets)===2 && array_sum(array_column($buckets,'total'))===3,'Daily buckets do not include a synthetic grand-total bucket');
    aggregateCheck(array_sum(array_column($buckets,'active'))===3,'Daily distinct counts remain independent from the period distinct count');
    aggregateCheck(count($sql)===2,'Current total and daily series must share one scan; previous period needs one scan, saw '.count($sql));
    [$empty,, $emptyBuckets]=(new ReflectionMethod($dashboard,'values'))->invoke($dashboard,
        Db::table('operations_aggregate_fixture')->where('tenant_id',99),'created_at',['total'=>'COUNT(*)','amount'=>'SUM(amount)']);
    aggregateCheck((int)($empty['total']??0)===0 && $emptyBuckets===[],'Empty windows do not produce an invented trend bucket');
    echo "PASS operations rollup totals, distinct users, tenant isolation, boundaries and two-query budget\n";
} finally {
    Db::execute('DROP TEMPORARY TABLE operations_aggregate_fixture');
    Db::execute('SET SESSION sql_mode=?',[$originalMode]);
}
