<?php
require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\model\ai\{AiConsumptionLog, AiAppTask};
use app\common\service\point\PointService;
use app\common\service\power\{MarketImageItemBilling as Billing, MarketImageModelRuntimeService as Runtime, MarketPriceRuleService as Rules, PowerMarketService, TenantPowerMarketService};
use think\facade\Db;

$database = config('database.connections.mysql');
if (($database['hostname'] ?? '') !== '127.0.0.1' || ($database['database'] ?? '') !== 'z_cn') throw new RuntimeException('Local fixture DB only');
$tenant = 1;
$user = (int)Db::name('user')->where('tenant_id',$tenant)->where('user_money','>',20)->value('id');
if ($user <= 0) throw new RuntimeException('Local funded fixture missing');
function imageAssert(bool $pass, string $label): void {
    if (!$pass) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function wallets(int $tenant, int $user): array {
    return [(float)Db::name('tenant')->where('id',$tenant)->value('point_balance'),
        (float)Db::name('user')->where('id',$user)->value('user_money')];
}
$before = wallets($tenant, $user);
Db::startTrans();
try {
    $nonce = bin2hex(random_bytes(5));
    $product = Db::name('power_market_product')->insertGetId(['product_code'=>'billing-'.$nonce,'name'=>'Fixture',
        'resource_type'=>'model','model_type'=>'image','upstream_model_code'=>'billing-'.$nonce,
        'upstream_resource_key'=>'billing-'.$nonce,'status'=>1]);
    $decl = ['price'=>['points'=>'2'],'sku_key'=>'fixture','usage_unit'=>'per_call','meter_summary'=>'按次',
        'input_image_bindings'=>['image_urls'],'charge_base_mode'=>'add','charge_items'=>[
            ['code'=>'input_images','rate'=>'1','unit'=>'input_image','included_count'=>0],
    ]];
    $sync = new ReflectionMethod(PowerMarketService::class,'upsertSku');
    $sync->invoke(null,$product,$decl,['resource'=>['type_code'=>'image','model_code'=>'billing-'.$nonce]]);
    $row = \app\common\model\power\PowerMarketSku::where('product_id',$product)->find()->toArray();
    $sku = (int)$row['id'];
    $platform = Billing::display(Billing::prices($row, 0),0);
    PowerMarketService::savePrices($product,[['sku_id'=>$sku,'manual_override'=>false,'sale_status'=>1,
        'item_prices'=>['input_images'=>['expected'=>$platform['items'][1]['sale'],'value'=>5]]]]);
    $tenantPricing = Billing::display(Billing::prices($row, $tenant),$tenant);
    TenantPowerMarketService::savePrices($tenant,$product,[['sku_id'=>$sku,'manual_override'=>false,'sale_status'=>1,
        'item_prices'=>['input_images'=>['expected'=>$tenantPricing['items'][1]['sale'],'value'=>7]]]]);
    $decl['charge_items'][0]['rate'] = 2;
    $sync->invoke(null,$product,$decl,['resource'=>['type_code'=>'image','model_code'=>'billing-'.$nonce]]);
    $fresh = \app\common\model\power\PowerMarketSku::find($sku)->toArray();
    $price = Billing::prices($fresh,$tenant);
    imageAssert($price['items']['input_images']['rate']==='2.000000' && $price['items']['input_images']['platform']==='5.000000'
        && $price['items']['input_images']['tenant']==='7.000000','sync updates cost and preserves both manual per-image rates');

    $token=['price'=>['points'=>0],'reserve_upper_points'=>5,'charge_base_mode'=>'replace','charge_items'=>[]];
    foreach (['text_input_uncached','text_input_cached','image_input_uncached','image_input_cached','image_output'] as $code) {
        $token['charge_items'][]=['code'=>$code,'unit'=>'token','unit_size'=>1000000,'rate'=>1000];
    }
    $token['sku_key'] = 'token-fixture';
    $token['usage_unit'] = 'per_call';
    $sync->invoke(null,$product,$token,['resource'=>['type_code'=>'image','model_code'=>'billing-'.$nonce]]);
    $tokenRow = \app\common\model\power\PowerMarketSku::where(['product_id'=>$product,'sku_key'=>'token-fixture'])->find()->toArray();
    $tokenSku = (int)$tokenRow['id'];
    $platformToken = array_column(Billing::display(Billing::prices($tokenRow,0),0)['items'],null,'code');
    PowerMarketService::savePrices($product,[['sku_id'=>$tokenSku,'manual_override'=>false,'sale_status'=>1,
        'item_prices'=>['image_output'=>['expected'=>$platformToken['image_output']['sale'],'value'=>1200]]]]);
    $tenantToken = array_column(Billing::display(Billing::prices($tokenRow,$tenant),$tenant)['items'],null,'code');
    TenantPowerMarketService::savePrices($tenant,$product,[['sku_id'=>$tokenSku,'manual_override'=>false,'sale_status'=>1,
        'item_prices'=>['image_output'=>['expected'=>$tenantToken['image_output']['sale'],'value'=>1350]]]]);
    $token['charge_items'][1]['rate'] = 1100;
    $sync->invoke(null,$product,$token,['resource'=>['type_code'=>'image','model_code'=>'billing-'.$nonce]]);
    $nextToken = Billing::prices(\app\common\model\power\PowerMarketSku::find($tokenSku)->toArray(),$tenant);
    imageAssert($nextToken['items']['image_output']['platform']==='1200.000000'
        && $nextToken['items']['image_output']['tenant']==='1350.000000'
        && $nextToken['items']['text_input_cached']['rate']==='1100.000000','sync preserves both manual Token rates and updates inherited Token costs');
    $contract = Billing::declaration($token,'fixture');
    $price = Billing::prices(['id'=>0,'product_id'=>0,'source_payload'=>['image_item_billing'=>$contract]],$tenant,[],[]);
    $receipt=['token_billing'=>['status'=>'settled','actual_points'=>'1','items'=>array_map(static fn($item)=>[
        'code'=>$item['code'],'tokens'=>$item['code']==='image_output'?1000:0,'points_per_million_tokens'=>$item['rate'],
    ],$token['charge_items'])]];
    $settle = new ReflectionMethod(Runtime::class,'settle');
    foreach (['receipt','refund'] as $case) {
        $initial=wallets($tenant,$user);
        $number='ITEM'.bin2hex(random_bytes(6));
        $task=AiAppTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>$number,'app_code'=>'aigc_image',
            'action_code'=>'generate','status'=>'running','idempotency_key'=>$number,'create_time'=>time(),'update_time'=>time()]);
        $consumption=AiConsumptionLog::create(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],
            'consume_no'=>$number,'app_code'=>'aigc_image','action_code'=>'generate','resource_type'=>'model',
            'model_code'=>'mock-local','billing_status'=>'reserved','run_status'=>'reserved','reserved_tenant_cost'=>5,
            'reserved_user_price'=>5,'price_snapshot'=>['image_item_pricing'=>$price,'usage_unit'=>'per_call','image_item_facts'=>[]],
            'quantity'=>1,'usage_unit'=>'per_call','create_time'=>time(),'update_time'=>time()]);
        $id=(int)$consumption['id'];
        PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,5,5,$number.'-reserve','local image billing fixture');
        $images=[['image_uri'=>'fixtures/placeholder.png']];
        if ($case==='receipt') {
            $settle->invoke(null,$id,$images,'','mock-task',['token_billing'=>['status'=>'reconciliation_pending']]);
            imageAssert(AiConsumptionLog::find($id)['billing_status']==='reserved','pending receipt retains both reservations');
            $settle->invoke(null,$id,$images,'','mock-task',$receipt);
            $after=wallets($tenant,$user);
            imageAssert(round($initial[0]-$after[0],2)===1.0 && round($initial[1]-$after[1],2)===1.0,'settled receipt charges both wallets once and returns excess');
            $settle->invoke(null,$id,$images,'','mock-task',$receipt);
        } else {
            Runtime::fail($id,'mock failure','upstream_failed');
            $after=wallets($tenant,$user);
            imageAssert($initial===$after,'failed generation releases both reservations');
            Runtime::fail($id,'mock failure','upstream_failed');
        }
        imageAssert(wallets($tenant,$user)===$after,"duplicate $case is idempotent");
    }
} finally {
    Db::rollback();
}
imageAssert(wallets($tenant,$user)===$before,'local fixture accounts rolled back');
