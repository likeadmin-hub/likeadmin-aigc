<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();

use app\common\enum\AdminTerminalEnum;
use app\common\service\workbench\TenantTaskStatistics;
use think\facade\Db;

$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['127.0.0.1','localhost'], true) || $config['database'] !== 'z_cn') {
    throw new RuntimeException('Requires the local fixture database');
}
function checkStat($ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$tenant = 987654221;
$at = time();
request()->tenantId = $tenant;
request()->source = AdminTerminalEnum::TENANT;
Db::startTrans();
try {
    Db::name('tenant')->insert(['id'=>$tenant, 'sn'=>'statfixture', 'name'=>'统计验收', 'tactics'=>0, 'point_balance'=>222,'create_time'=>$at,'update_time'=>$at]);
    Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'recharge','name'=>'recharge_ratio','value'=>'10','create_time'=>$at,'update_time'=>$at]);
    $consume = static function ($who, $user, $state, $cost, $price, $stamp) {
        Db::name('ai_consumption_log')->insert(['tenant_id'=>$who,'user_id'=>$user,'consume_no'=>bin2hex(random_bytes(12)),
            'app_code'=>'fixture_tool','model_code'=>'fixture_model','billing_status'=>$state,
            'actual_tenant_cost'=>$cost,'actual_user_price'=>$price,'finish_time'=>$stamp,'create_time'=>$stamp]);
    };
    $consume($tenant, 11, 'settled', 8, 20, $at);
    $consume($tenant, 12, 'settled', 4, 5, $at);
    $consume($tenant, 11, 'refunded', 99, 99, $at);
    $consume($tenant, 11, 'reserved', 50, 50, $at);
    $consume($tenant+1, 11, 'settled', 100, 200, $at);
    $consume($tenant, 11, 'settled', 33, 77, $at-86400);
    foreach ([0,0,1] as $parent) {
        Db::name('ai_app_task')->insert(['tenant_id'=>$tenant, 'user_id'=>11, 'task_no'=>bin2hex(random_bytes(8)),
            'idempotency_key'=>bin2hex(random_bytes(12)), 'app_code'=>'fixture_tool','parent_task_id'=>$parent,
            'status'=>'success','create_time'=>$at]);
    }
    $stats = TenantTaskStatistics::report($tenant, []);
    checkStat($stats['range']['key']==='today' && $stats['user_points']==25 && $stats['cost_points']==12, 'settled only, date and tenant boundaries');
    checkStat($stats['user_amount']==2.50 && $stats['cost_amount']==1.20 && $stats['gross_profit']==1.30, 'tenant ratio applies to both sides');
    $stats = TenantTaskStatistics::report($tenant, ['user_id'=>11,'app_code'=>'fixture_tool']);
    checkStat($stats['balance']==222 && $stats['user_amount']==2 && $stats['cost_amount']==0.8 && $stats['gross_profit']==1.2, 'tenant ratio and filters');
    checkStat(array_sum($stats['trend']['cost_points'])==8 && $stats['models'][0]['uses']==3, 'trend versus model calls');
    checkStat($stats['tools'][0]['uses']==2 && $stats['users'][0]['uses']==2 && $stats['statuses'][0]['uses']==2, 'top-level task rankings');
    $empty = TenantTaskStatistics::report($tenant, ['app_code'=>'not_run']);
    checkStat($empty['balance']==222 && $empty['user_points']==0 && !$empty['models'], 'empty filtered range retains current balance');
    $day = (new DateTimeImmutable('@'.($at-86400)))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    $previous = TenantTaskStatistics::report($tenant, ['range'=>'custom','start_date'=>$day,'end_date'=>$day]);
    checkStat($previous['user_points']==77 && $previous['cost_points']==33 && $previous['gross_profit']==4.4, 'custom Shanghai date');
    $consume($tenant, 13, 'settled', 15, 10, $at);
    $loss = TenantTaskStatistics::report($tenant, ['user_id'=>13]);
    checkStat($loss['gross_profit']==-0.5, 'loss remains negative');
    echo "PASS task statistics: settlements, scope, tenant ratio, balance, filters and rankings\n";
} finally {
    Db::rollback();
}
