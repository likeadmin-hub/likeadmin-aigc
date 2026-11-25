<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();

use app\common\service\workbench\PlatformTaskStatistics;
use app\common\service\workbench\PlatformTaskCost;
use app\common\service\workbench\PlatformSupplierCostReview;
use app\common\model\ai\AiConsumptionEvent;
use app\common\service\power\MarketFileQaTokenPricing;
use app\common\service\power\MarketPriceRuleService;
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
    $productId = Db::name('power_market_product')->insertGetId(['name'=>'Token fixture', 'product_code'=>$tool, 'upstream_resource_key'=>$tool,
        'resource_type'=>'model', 'model_type'=>'text', 'status'=>1]);
    $skuId = Db::name('power_market_sku')->insertGetId(['product_id'=>$productId, 'sku_key'=>'token', 'title'=>'Token fixture',
        'upstream_price'=>0, 'sale_points'=>0, 'status'=>1, 'sale_status'=>1, 'usage_unit'=>'token', 'usage_unit_size'=>1000,
        'source_payload'=>json_encode(['effective_rate_config'=>['input'=>10, 'cached_input'=>2, 'output'=>20]])]);
    $sku = Db::name('power_market_sku')->where('id',$skuId)->find();
    $rates = MarketFileQaTokenPricing::display($sku, $ids[0]);
    $sides = [];
    foreach (['input','output'] as $side) {
        $sides[$side] = ['sku_id'=>$skuId, 'usage_unit_size'=>$rates['usage_unit_size'], 'token_rate_side'=>$side,
            'platform_price'=>$rates['cost'][$side], 'tenant_price'=>$rates['sale'][$side],
            'cached_platform_price'=>$rates['cost']['cached_input'], 'cached_tenant_price'=>$rates['sale']['cached_input']];
    }
    $historical = MarketPriceRuleService::captureSnapshot($ids[0], $sides, ['user_id'=>17]);
    Db::name('power_market_sku')->where('id',$skuId)->update(['source_payload'=>json_encode(['effective_rate_config'=>['input'=>40, 'cached_input'=>8, 'output'=>80]])]);
    expectStat(supplier($historical, ['prompt_tokens'=>1000,'cached_input_tokens'=>500,'completion_tokens'=>500])===16.,
        'fresh orders freeze supplier token rates before upstream calls and survive later catalog changes');
    $add = static function ($tenant, $state, $sale, $price, $time, array $usage = []) use ($tool) {
        return Db::name('ai_consumption_log')->insertGetId(['consume_no'=>bin2hex(random_bytes(12)),'tenant_id'=>$tenant,'user_id'=>17,
            'app_code'=>$tool,'model_code'=>'test_model','billing_status'=>$state,'finish_time'=>$time,'create_time'=>$time,
            'upstream_request_id'=>'fixture-receipt','actual_user_price'=>9999,'actual_tenant_cost'=>$sale,
            'quantity'=>2,'price_snapshot'=>json_encode($price),'usage_snapshot'=>json_encode($usage)]);
    };
    $knownId = $add($ids[0], 'settled', 120, ['upstream_price'=>25], $at);
    $add($ids[1], 'settled', 300, ['upstream_price'=>175], $at);
    $add($ids[0], 'settled', 5, ['upstream_price'=>0], $at);
    $refundedId = $add($ids[0], 'refunded', 500, [], $at);
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
    $unknownId = $add($ids[1], 'settled', 10, ['input'=>['platform_price'=>100], 'output'=>['platform_price'=>200]], $at,
        ['prompt_tokens'=>41, 'completion_tokens'=>5, 'other'=>'not part of the review']);
    $r = PlatformTaskStatistics::report($filter);
    expectStat($r['unpriced_count']===1 && $r['cost_points']===null && $r['gross_profit']===null && $r['known_cost_points']===400. && $r['supply_points']===435., 'incomplete costs preserve income and uncertainty');
    expectStat(!isset($r['cost_review']), 'report permission does not include the review rows by default');
    $review = PlatformTaskStatistics::report($filter, true)['cost_review']['rows'];
    expectStat(count($review)===1 && $review[0]['id']===$unknownId && $review[0]['usage']===['prompt_tokens'=>41,'completion_tokens'=>5]
        && $review[0]['upstream_request_id']==='fixture-receipt', 'review lists only missing records and approved evidence fields');
    expectStat(PlatformTaskStatistics::report($filter+['tenant_id'=>$ids[0]], true)['cost_review']['rows']===[], 'review respects the tenant filter');
    expectStat(PlatformTaskStatistics::report($filter+['cost_review_page'=>2], true)['cost_review']['rows']===[], 'review respects paging');
    $before = Db::name('ai_consumption_log')->where('id',$unknownId)->find();
    foreach ([[$knownId,'1','bill'],[$refundedId,'1','bill'],[$unknownId,'-1','bill'],[$unknownId,'1','']] as [$id,$points,$ref]) {
        try {
            PlatformSupplierCostReview::confirm($id, 1, $points, $ref);
            throw new RuntimeException('invalid confirmation succeeded');
        } catch (InvalidArgumentException $expected) {}
    }
    PlatformSupplierCostReview::confirm($unknownId, 1, '15.250000', 'bill-verified-1');
    PlatformSupplierCostReview::confirm($unknownId, 1, '15.25', 'bill-verified-1');
    try {
        PlatformSupplierCostReview::confirm($unknownId, 1, '16', 'bill-verified-2');
        throw new RuntimeException('verified amount was overwritten');
    } catch (InvalidArgumentException $expected) {}
    $events = Db::name('ai_consumption_event')->where(['consumption_id'=>$unknownId,'event_type'=>AiConsumptionEvent::SUPPLIER_COST_CONFIRMED])->select()->toArray();
    expectStat(count($events)===1 && json_decode($events[0]['payload_summary'],true)===['supplier_points'=>'15.25','reference'=>'bill-verified-1','admin_id'=>1], 'audit receipt remains immutable on retry or conflict');
    expectStat(Db::name('ai_consumption_log')->where('id',$unknownId)->find()===$before, 'settlement and original snapshots are unchanged');
    expectStat(PlatformTaskStatistics::report($filter, true)['cost_points']===415.25, 'confirmed point amount is included in the total');
    $zeroId = $add($ids[1], 'settled', 0, [], $at);
    PlatformSupplierCostReview::confirm($zeroId, 1, '0.000000', 'zero-charge-bill');
    $r = PlatformTaskStatistics::report($filter, true);
    expectStat($r['unpriced_count']===0 && $r['gross_profit']===0.2 && $r['cost_review']['rows']===[], 'confirmed zero is counted as known and margin is restored');
    try {
        PlatformTaskStatistics::report(['tenant_id'=>'wrong']);
        throw new RuntimeException('invalid tenant widened scope');
    } catch (InvalidArgumentException $expected) {}
    echo "PASS platform statistics: all tenants, single tenant, two 1:100 conversions, missing and zero costs, negative margins, activity and trends\n";
} finally { Db::rollback(); }
