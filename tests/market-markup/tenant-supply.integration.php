<?php
declare(strict_types=1);

use app\common\service\open_platform\OpenMarketBilling;
use app\common\service\open_platform\OpenStore;
use app\common\service\power\MarketFileQaTokenPricing;
use app\common\service\power\MarketImageItemBilling;
use app\common\service\power\MarketPriceRuleService;
use app\common\service\power\TenantSupplyMarkup;
use think\facade\Db;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage().' at '.$e->getFile().':'.$e->getLine().PHP_EOL); exit(1); });

function sameSupplyValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message.' '.json_encode([$expected, $actual], JSON_UNESCAPED_UNICODE));
}

function supplyCall(int $tenant, int $user): array
{
    $rid = 'req_'.bin2hex(random_bytes(12));
    $r = ['request_id' => $rid, 'tenant_id' => $tenant, 'user_id' => $user, 'key_id' => 0,
        'kind' => 'market', 'method' => 'POST', 'path' => '/fixture/supply', 'payload_hash' => hash('sha256',$rid),
        'snapshot' => '{}', 'response_blob' => '', 'consumption_ids' => '[]'];
    $r['id'] = OpenStore::table('request')->insertGetId($r);
    return $r;
}

if (config('database.connections.mysql.hostname') !== '127.0.0.1' || config('database.connections.mysql.database') !== 'z_cn') {
    throw new RuntimeException('Run on the local rollback fixture only');
}
Db::startTrans();
try {
    $suffix = bin2hex(random_bytes(4));
    $tenant = Db::name('tenant')->insertGetId(['sn' => $suffix, 'name' => 'Supply markup fixture', 'tactics'=>0,
        'point_balance'=>100, 'create_time'=>time()]);
    $user = Db::name('user')->insertGetId(['tenant_id'=>$tenant,'sn'=>random_int(900000000,999999999),
        'account'=>'supply_'.$suffix,'user_money'=>100,'create_time'=>time()]);
    request()->tenantId = $tenant;
    $other = Db::name('tenant')->insertGetId(['sn' => 'b'.$suffix, 'name' => 'Standard supply fixture', 'tactics'=>0,'create_time'=>time()]);
    $product = Db::name('power_market_product')->insertGetId(['name'=>'Fixture', 'product_code'=>'supply_'.$suffix,
        'upstream_resource_key'=>'supply_'.$suffix, 'resource_type'=>'model','model_type'=>'image','status'=>1]);
    $skuId = Db::name('power_market_sku')->insertGetId(['product_id'=>$product, 'sku_key'=>'fixture','title'=>'Fixture',
        'upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'usage_unit'=>'image','usage_unit_size'=>1]);
    MarketPriceRuleService::manual(0,$product,$skuId,10);
    Db::name('power_market_price_rule')->insert(['owner_id'=>$tenant,'scope_type'=>'product','scope_id'=>$product,'mode'=>'percent','value'=>25]);
    Db::name('tenant_power_supply_markup')->insert(['tenant_id'=>$tenant,'rate_percent'=>'20.00','revision'=>1,'updated_by'=>1,'update_time'=>time()]);
    $model = Db::name('power_market_sku')->where('id',$skuId)->find();
    sameSupplyValue('10.000000', MarketPriceRuleService::tenant($other,$model)['platform']['price'], 'tenant isolation');
    sameSupplyValue('12.000000', MarketPriceRuleService::tenant($tenant,$model)['platform']['price'], 'tenant cost');
    sameSupplyValue('15.000000', MarketPriceRuleService::tenant($tenant,$model)['tenant']['price'], 'tenant retail');
    $imageSnapshot = ['pricing_source'=>'power_market', 'sku_id'=>$skuId, 'platform_unit_cost'=>12, 'tenant_unit_price'=>15];
    MarketPriceRuleService::captureSnapshot($tenant, $imageSnapshot);
    $staleRejected = false;
    try {
        MarketPriceRuleService::captureSnapshot($tenant, array_merge($imageSnapshot, ['platform_unit_cost'=>10]));
    } catch (RuntimeException $e) {
        $staleRejected = str_contains($e->getMessage(), '图像模型价格已更新');
    }
    sameSupplyValue(true, $staleRejected, 'stale legacy image estimate rejected');
    sameSupplyValue('0.000002', TenantSupplyMarkup::apply('0.000001',['rate_percent'=>'50']), 'six-digit half up');
    sameSupplyValue('0.000000', TenantSupplyMarkup::price($tenant,0), 'zero price');
    foreach ([0,$tenant] as $owner) {
        OpenStore::table('setting')->replace()->insert(['tenant_id'=>$owner,'enabled'=>1,'config'=>json_encode(['models'=>[(string)$product]])]);
    }
    $op = ['executor'=>'json','billable'=>true,'product_id'=>$product,'billing'=>[['sku_id'=>$skuId,
        'basis'=>'usage','usage_path'=>'data.count','max_units'=>3]]];
    $budget = OpenMarketBilling::quote($tenant,$op,[]);
    sameSupplyValue([36.0,45.0],[$budget['tenant'],$budget['user']], 'API maximum reservation');
    $request = supplyCall($tenant,$user);
    OpenMarketBilling::reserve($request,$budget);
    $balances = static fn() => [(float)Db::name('tenant')->where('id',$tenant)->value('point_balance'),
        (float)Db::name('user')->where('id',$user)->value('user_money')];
    sameSupplyValue([64.0,55.0], $balances(), 'wallet reservation');
    $pricing = MarketFileQaTokenPricing::snapshot($model,['usage_unit_size'=>1000,'effective_rate_config'=>[
        'input'=>10,'cached_input'=>0,'output'=>20]],[],[],true,TenantSupplyMarkup::settings($tenant));
    sameSupplyValue(['input'=>'12.000000','cached_input'=>'0.000000','output'=>'24.000000'],$pricing['platform'],'Token components');
    $contract = MarketImageItemBilling::declaration(['price'=>['points'=>10],'meter_summary'=>'按次','charge_base_mode'=>'add',
        'charge_items'=>[['code'=>'input_images','rate'=>'2','unit'=>'input_image','included_count'=>1]]], 'official-33-7b77ec425b57b520cd');
    $items = MarketImageItemBilling::prices(array_replace($model,['source_payload'=>['image_item_billing'=>$contract]]),$tenant,[],[]);
    sameSupplyValue([14.4,14.4],MarketImageItemBilling::amounts($items,['input_count'=>2],1),'one included image');
    Db::name('tenant_power_supply_markup')->where('tenant_id',$tenant)->update(['rate_percent'=>'0.00','revision'=>2]);
    $expired = false;
    try { OpenMarketBilling::reserve(supplyCall($tenant,$user),$budget); }
    catch (RuntimeException $error) { $expired = str_contains($error->getMessage(),'重新'); }
    sameSupplyValue(true,$expired,'stale quotation rejected');
    OpenMarketBilling::settle($request,['data'=>['count'=>2]]);
    OpenMarketBilling::settle($request,['data'=>['count'=>2]]);
    sameSupplyValue([76.0,70.0], $balances(), 'original snapshot settles 24 and 30 exactly once');
    $bill = Db::name('ai_consumption_log')->where('consume_no',$request['request_id'])->find();
    sameSupplyValue('20.00', OpenStore::json($bill['price_snapshot'])['supply_markup']['rate_percent'], 'historical markup');
    $tenantBill = \app\common\service\ai\AiUsageService::consumptionDetail((int)$bill['id'], $tenant);
    sameSupplyValue(false,isset($tenantBill['price_snapshot']['supply_markup']), 'tenant bill hides platform pricing policy');
    sameSupplyValue(12.0,(float)$tenantBill['price_snapshot']['rules'][0]['prices']['tenant'], 'tenant bill retains actual supply price');
    $fresh = OpenMarketBilling::quote($tenant,$op,[]);
    sameSupplyValue([30.0,37.5],[$fresh['tenant'],$fresh['user']], 'new quotation follows restoration');
    $failed = supplyCall($tenant,$user);
    OpenMarketBilling::reserve($failed,$fresh);
    OpenMarketBilling::settle($failed,[],true);
    OpenMarketBilling::settle($failed,[],true);
    sameSupplyValue([76.0,70.0], $balances(), 'failure refunded exactly once');
    Db::name('tenant_power_supply_markup')->where('tenant_id',$tenant)->update(['rate_percent'=>'20.00','revision'=>3]);
    $budget = OpenMarketBilling::quote($tenant,$op,[]);
    foreach ([['tenant',32,100],['user',100,40]] as [$which,$tenantFunds,$userFunds]) {
        Db::name('tenant')->where('id',$tenant)->update(['point_balance'=>$tenantFunds]);
        Db::name('user')->where('id',$user)->update(['user_money'=>$userFunds]);
        $call = supplyCall($tenant,$user);
        $rejected = false;
        try { OpenMarketBilling::reserve($call,$budget); } catch (Throwable $e) { $rejected = str_contains($e->getMessage(),'不足'); }
        sameSupplyValue(true,$rejected,$which.' insufficient balance');
        sameSupplyValue(0,Db::name('ai_consumption_log')->where('consume_no',$call['request_id'])->count(),'reservation rollback');
        sameSupplyValue([(float)$tenantFunds,(float)$userFunds],$balances(),'wallet rollback');
    }
    echo "PASS isolated tenants, scalar and item rates, open API reserve, requote, frozen settlement, insufficient balances, refund and duplicate receipt\n";
} finally {
    Db::rollback();
}
