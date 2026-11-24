<?php
declare(strict_types=1);

use app\api\controller\app\aigc_watermark_removal\ConfigController;
use app\api\http\middleware\AppAccessMiddleware;
use app\common\service\app\aigc_action_transfer\AigcActionTransferService;
use app\common\service\power\MarketAppCostPricingService;
use app\common\service\power\MarketGenericImageAppRuntimeService;
use app\common\service\power\MarketPriceRuleService;
use app\common\service\power\TenantPowerMarketService;
use think\facade\Db;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e."\n"); exit(1); });
if (config('database.connections.mysql.hostname') !== '127.0.0.1' || config('database.connections.mysql.database') !== 'z_cn') {
    throw new RuntimeException('Local rollback fixture required');
}
function sameConsumer(mixed $want, mixed $have, string $message): void
{
    if ($have !== $want) throw new RuntimeException($message . ' ' . json_encode([$want,$have]));
}

Db::startTrans();
try {
    $suffix = bin2hex(random_bytes(4));
    $tenant = Db::name('tenant')->insertGetId(['sn'=>$suffix, 'name'=>'Consumer fixture', 'tactics'=>0,'create_time'=>time()]);
    Db::name('tenant_app')->insert(['tenant_id'=>$tenant,'app_code'=>'aigc_watermark_removal','expire_time'=>time()+3600]);
    request()->tenantId = $tenant;
    $product = Db::name('power_market_product')->insertGetId(['name'=>'Fixture', 'product_code'=>'consumer_'.$suffix,
        'upstream_resource_key'=>'consumer_'.$suffix, 'resource_type'=>'app_api',
        'upstream_app_code'=>'watermark_removal', 'upstream_api_code'=>'remove','status'=>1]);
    $sku = Db::name('power_market_sku')->insertGetId(['product_id'=>$product, 'sku_key'=>'fixture', 'title'=>'Fixture',
        'upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'usage_unit'=>'per_call','usage_unit_size'=>1]);
    MarketPriceRuleService::manual(0, $product, $sku, 10);
    Db::name('tenant_power_supply_markup')->insert(['tenant_id'=>$tenant,'rate_percent'=>'20.00','revision'=>1,'updated_by'=>1,'update_time'=>time()]);
    Db::name('power_market_price_rule')->insert(['owner_id'=>$tenant,'scope_type'=>'global','scope_id'=>0,'mode'=>'percent','value'=>25]);

    $request = request();
    $request->setMethod('GET');
    $request->setController('app.aigc_watermark_removal.Config');
    $request->setAction('detail');
    $response = (new AppAccessMiddleware())->handle($request, static fn() => (new ConfigController(app()))->detail());
    $payload = $response->getData();
    sameConsumer(1, $payload['code'], 'Customer config failed: '.($payload['msg'] ?? ''));
    $option = array_column($payload['data']['options'],null,'market_sku_id')[$sku];
    sameConsumer(15.0, $option['tenant_unit_price'], 'Customer retail catalog');
    sameConsumer(false, isset($option['platform_unit_cost']), 'Customer sees the tenant cost');
    $quote = MarketGenericImageAppRuntimeService::quote($tenant, ['market_product_id'=>$product, 'sku_id'=>$sku], 2);
    sameConsumer([24.0,30.0], [$quote['tenant_cost_points'],$quote['user_charge_points']], 'Runtime quote');
    $detail = TenantPowerMarketService::detail($tenant,$product)['skus'][0];
    sameConsumer([12.0,15.0,3.0], [$detail['platform_sale_points'],$detail['sale_points'],$detail['gross_margin_points']], 'Tenant purchase, retail, margin');

    $app = 'supply_fixture_'.$suffix;
    Db::name('power_market_product')->where('id',$product)->update(['upstream_app_code'=>$app,'upstream_api_code'=>'submit']);
    Db::name('power_market_sku')->where('id',$sku)->update(['usage_unit'=>'second']);
    $request->adminInfo = ['tenant_id'=>$tenant, 'admin_id'=>1];
    $request->withGet(['app_code'=>$app]);
    $tenantView = (new \app\tenantapi\controller\power\MarketController(app()))->appDetail()->getData()['data'];
    sameConsumer(15.0, $tenantView['min_price'], 'App detail retail minimum');
    $adminSku = $tenantView['apis'][0]['skus'][0];
    sameConsumer(12.0, $adminSku['platform_sale_points'], 'Tenant admin effective purchase price');
    sameConsumer([], array_intersect_key($adminSku, array_flip(['upstream_price','configured_sale_points','price_rule','source_payload'])), 'Tenant sees standard price');
    $dedicated = MarketAppCostPricingService::quote($tenant, $app, ['api_code'=>'submit','duration'=>2]);
    $estimate = (new ReflectionMethod(AigcActionTransferService::class,'buildEstimateFromQuote'))->invoke(null,
        ['mode'=>'fast','mode_label'=>'fast','face_count'=>1,'duration'=>2,'config'=>['price_matrix'=>['fast'=>0]]], $dedicated);
    sameConsumer([24.0,0.0],[$estimate['tenant_cost_points'],$estimate['user_charge_points']],'Independent free action transfer');
    $voiceApi = ['upstream_app_code'=>'voice_tts', 'upstream_api_code'=>'clone_voice'];
    Db::name('power_market_product')->where($voiceApi)->update(['status'=>0]);
    Db::name('power_market_product')->where('id',$product)->update($voiceApi);
    Db::name('power_market_sku')->where('id',$sku)->update(['usage_unit'=>'per_call']);
    $clone = \app\common\service\app\aigc_digital_human\VoiceCloneMarketPricingService::quote($tenant);
    sameConsumer(['12.00','15.00'], [$clone['platform_unit_cost'],$clone['tenant_unit_price']], 'Voice clone quote');
    echo "PASS shared customer config, runtime cost, tenant catalog, dedicated free application and voice clone; rolled back\n";
} finally {
    Db::rollback();
}
