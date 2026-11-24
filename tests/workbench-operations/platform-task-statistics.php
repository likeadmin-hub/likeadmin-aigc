<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();

use app\common\service\workbench\PlatformTaskStatistics;
use app\common\service\workbench\PlatformTaskCost;
use think\facade\Db;

function expectStat(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}
function supplier(array $price, array $usage, float $quantity = 1): ?float {
    return PlatformTaskCost::points(['price_snapshot'=>json_encode($price),'usage_snapshot'=>json_encode($usage),'quantity'=>$quantity]);
}
expectStat(supplier(['input'=>['upstream_price'=>10,'usage_unit_size'=>1000,'cached_platform_price'=>99,
    'pricing_rules'=>['upstream_rates'=>['cached_input'=>2]]], 'output'=>['upstream_price'=>20,'usage_unit_size'=>1000]],
    ['prompt_tokens'=>1000,'cached_input_tokens'=>500,'completion_tokens'=>500])===16., 'text input, cache and output');
expectStat(supplier(['image_item_pricing'=>['mode'=>'image_count','items'=>['base'=>['rate'=>20],'input_images'=>['rate'=>2]]]],
    ['billing_details'=>['items'=>[['code'=>'base','quantity'=>3,'unit_points'=>700],['code'=>'input_images','quantity'=>2]]]])===64., 'image items use supplier rates');
expectStat(supplier(['token_pricing'=>['usage_unit_size'=>100,'upstream'=>['input'=>10,'cached_input'=>2,'output'=>20]]],
    ['token_breakdown'=>['input'=>50,'cached_input'=>50,'output'=>20]])===10., 'file token breakdown');
expectStat(supplier(['image_item_pricing'=>['mode'=>'token_items'],'settled_supplier_points'=>0.25], [])===0.25, 'confirmed token item charge');
expectStat(supplier(['image_item_pricing'=>['mode'=>'token_items'],'upstream_price'=>0], [])===null, 'historical token items with no receipt');
expectStat(supplier(['pricing_source'=>'image_channel','upstream_unit_cost'=>99], ['actual_quantity'=>5])===null, 'legacy retail fallback is not supplier cost');
expectStat(supplier(['billing_owner'=>'gateway','rules'=>[['sku_id'=>22,'basis'=>'usage','unit_size'=>100,'pricing_rules'=>['platform'=>['base'=>3]]]]],
    [['sku_id'=>22,'units'=>200]])===6., 'mixed API metering');
$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['127.0.0.1','localhost'], true) || $config['database'] !== 'z_cn') {
    throw new RuntimeException('Requires the local fixture database');
}
$at = time();
$tool = 'stat'.bin2hex(random_bytes(6));
Db::startTrans();
try {
    $ids = [];
    foreach ([23, 59] as $balance) {
        $ids[] = Db::name('tenant')->insertGetId(['sn'=>bin2hex(random_bytes(6)), 'name'=>'统计测试','tactics'=>0,'point_balance'=>$balance,'create_time'=>$at]);
    }
    $add = static function ($tenant, $state, $sale, $price, $time) use ($tool) {
        Db::name('ai_consumption_log')->insert(['consume_no'=>bin2hex(random_bytes(12)),'tenant_id'=>$tenant,'user_id'=>17,
            'app_code'=>$tool,'model_code'=>'test_model','billing_status'=>$state,'finish_time'=>$time,'create_time'=>$time,
            'actual_user_price'=>9999,'actual_tenant_cost'=>$sale,'quantity'=>2,'price_snapshot'=>json_encode($price),'usage_snapshot'=>'{}']);
    };
    $add($ids[0], 'settled', 120, ['upstream_price'=>25], $at);
    $add($ids[1], 'settled', 300, ['upstream_price'=>175], $at);
    $add($ids[0], 'settled', 5, ['upstream_price'=>0], $at);
    $add($ids[0], 'refunded', 500, [], $at);
    $add($ids[1], 'reserved', 500, [], $at);
    $add($ids[0], 'settled', 500, [], $at-86400);
    foreach ($ids as $tid) Db::name('ai_app_task')->insert(['tenant_id'=>$tid,'user_id'=>17,'task_no'=>bin2hex(random_bytes(8)),
        'idempotency_key'=>bin2hex(random_bytes(12)),'app_code'=>$tool,'parent_task_id'=>0,'status'=>'success','create_time'=>$at]);
    $filter = ['app_code'=>$tool];
    $r = PlatformTaskStatistics::report($filter);
    expectStat($r['range']['key']==='today' && $r['supply_amount']===4.25 && $r['cost_amount']===4.00 && $r['gross_profit']===0.25, 'all tenants, settlement boundaries, platform margin');
    expectStat(count($r['users'])===2 && $r['users'][0]['tenant_id'] != $r['users'][1]['tenant_id'] && $r['tools'][0]['uses']==2 && $r['models'][0]['uses']==5, 'cross-tenant users and rankings');
    $one = PlatformTaskStatistics::report($filter+['tenant_id'=>$ids[1], 'user_id'=>17]);
    expectStat($one['gross_profit']===-0.5 && $one['balance']==59 && array_sum($one['trend']['cost_points'])==350 && count($one['users'])===1, 'tenant selection, loss, trend and current balance');
    $add($ids[1], 'settled', 10, [], $at);
    $r = PlatformTaskStatistics::report($filter);
    expectStat($r['unpriced_count']===1 && $r['cost_points']===null && $r['gross_profit']===null && $r['known_cost_points']===400. && $r['supply_points']===435., 'incomplete costs preserve income and uncertainty');
    try {
        PlatformTaskStatistics::report(['tenant_id'=>'wrong']);
        throw new RuntimeException('invalid tenant widened scope');
    } catch (InvalidArgumentException $expected) {}
    echo "PASS platform statistics: all tenants, single tenant, two 1:100 conversions, missing and zero costs, negative margins, activity and trends\n";
} finally { Db::rollback(); }
