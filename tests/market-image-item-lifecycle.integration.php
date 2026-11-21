<?php
require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\model\ai\{AiConsumptionLog, AiAppTask};
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\service\app\aigc_image\AigcImageService;
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
    $economyResource = ['resource'=>['type_code'=>'image','model_code'=>'gpt-image-2-6-economy','channel_code'=>'official']];
    $economy = [
        'sku_key'=>'group_image_to_image_economy-1k', 'locked_params'=>['generation_type'=>'image_to_image'],
        'price'=>['points'=>20,'unit'=>'per_call'], 'usage_unit'=>'per_call', 'usage_unit_size'=>1,
        'billing_mode'=>'fixed','meter_summary'=>'按次',
    ];
    $sync->invoke(null,$product,$economy + ['charge_items'=>$decl['charge_items']],$economyResource);
    $economyRow = \app\common\model\power\PowerMarketSku::where('product_id',$product)->where('sku_key',$economy['sku_key'])->find();
    imageAssert(isset($economyRow['source_payload']['image_item_billing']['error']),'older Economy declaration reproduces the cached item error');
    $economyRow->save(['sale_status'=>0]);
    $incomplete = array_replace($economy, ['meter_summary'=>'']);
    $sync->invoke(null,$product,$incomplete,$economyResource);
    imageAssert(isset(\app\common\model\power\PowerMarketSku::find($economyRow['id'])['source_payload']['image_item_billing']['error']),
        'an incomplete Economy response cannot clear a cached billing error');
    $sync->invoke(null,$product,$economy,$economyResource);
    $economyRow = \app\common\model\power\PowerMarketSku::find($economyRow['id']);
    imageAssert(!isset($economyRow['source_payload']['image_item_billing']) && (int)$economyRow['sale_status']===0,
        'authoritative Economy fixed pricing clears stale item error and preserves manual shelf state');
    $economyRow->delete();

    $meter = new ReflectionMethod(Runtime::class,'meterMarket');
    try {
        $meter->invoke(null,['sku'=>['usage_unit'=>'business_unit','source_payload'=>['meter_summary'=>'百万像素，总点数向上取整']]],[]);
        throw new RuntimeException('A direct SKU estimate accepted legacy pixel billing');
    } catch (InvalidArgumentException $error) {
        imageAssert($error->getMessage()==='该规格尚无可核验的像素报价','direct SKU estimate rejects unbounded pixel billing');
    }
    $fixtureResource = ['resource'=>['type_code'=>'image','model_code'=>'billing-'.$nonce]];
    $bad = array_replace($decl, ['sku_key'=>'bad-items','input_image_bindings'=>[]]);
    $sync->invoke(null,$product,$bad,$fixtureResource);
    $sync->invoke(null,$product,$decl,$fixtureResource);
    $row = \app\common\model\power\PowerMarketSku::where('product_id',$product)->where('sku_key','fixture')->find()->toArray();
    $sku = (int)$row['id'];
    $selection = ['market_product_id'=>$product,'prompt'=>'A local image quote'];
    $quote = Runtime::quote($tenant,$selection);
    imageAssert($quote['market_sku_id']===$sku,'automatic selection skips the invalid item contract ahead of a valid matching SKU');
    $badId = (int)\app\common\model\power\PowerMarketSku::where('product_id',$product)->where('sku_key','bad-items')->value('id');
    try {
        Runtime::quote($tenant,$selection + ['market_sku_id'=>$badId]);
        throw new RuntimeException('An explicitly selected invalid SKU was silently replaced');
    } catch (Exception $error) {
        imageAssert($error->getMessage()==='该规格缺少已核验的输入图片字段','explicit invalid SKU reports its original pricing error');
    }
    \app\common\model\power\PowerMarketSku::destroy($badId);

    $pixelProduct = Db::name('power_market_product')->insertGetId(['product_code'=>'pixel-'.$nonce,'name'=>'Pixel fixture',
        'resource_type'=>'model','model_type'=>'image','upstream_model_code'=>'pixel-'.$nonce,
        'upstream_resource_key'=>'pixel-'.$nonce,'status'=>1]);
    $pixel = ['sku_key'=>'unbounded','price'=>['points'=>16],'usage_unit'=>'business_unit',
        'meter_summary'=>'百万像素，总点数向上取整'];
    $sync->invoke(null,$pixelProduct,$pixel,$fixtureResource);
    $sync->invoke(null,$pixelProduct,array_replace($pixel,['sku_key'=>'unknown-meter','meter_summary'=>'未识别的计费规则']),$fixtureResource);
    $pixelSelection = array_replace($selection,['market_product_id'=>$pixelProduct]);
    try {
        Runtime::quote($tenant,$pixelSelection + ['market_sku_id'=>$sku]);
        throw new RuntimeException('A SKU from a different model was accepted');
    } catch (Exception $error) {
        imageAssert($error->getMessage()==='所选图片规格与当前模型不一致，请重新选择规格','explicit SKU cannot switch the selected model');
    }
    try {
        Runtime::quote($tenant,$selection + ['market_sku_id'=>$sku,'channel'=>'market_image_model:'.$pixelProduct]);
        throw new RuntimeException('A stale product ID shadowed the selected model channel');
    } catch (Exception $error) {
        imageAssert($error->getMessage()==='图片模型选择不一致，请重新选择模型','conflicting model identifiers require a refreshed selection');
    }
    try {
        Runtime::quote($tenant,$pixelSelection);
        throw new RuntimeException('An unavailable meter was quoted');
    } catch (Exception $error) {
        imageAssert($error->getMessage()==='该规格尚无可核验的像素报价','automatic selection preserves the billing error when no SKU can be quoted');
    }
    $option = array_column(Runtime::options($tenant),null,'market_product_id')[$pixelProduct];
    imageAssert(!$option['available'] && $option['skus']===[],'unknown scalar contracts do not appear as selectable image SKUs');

    $platform = Billing::display(Billing::prices($row, 0),0);
    PowerMarketService::savePrices($product,[['sku_id'=>$sku,'state_only'=>true,'sale_status'=>0]]);
    PowerMarketService::savePrices($product,[['sku_id'=>$sku,'items_only'=>true,
        'item_prices'=>['input_images'=>['expected'=>$platform['items'][1]['sale'],'value'=>5]]]]);
    imageAssert((int)\app\common\model\power\PowerMarketSku::find($sku)['sale_status']===0,'platform item-only setting preserves paused SKU state');
    PowerMarketService::savePrices($product,[['sku_id'=>$sku,'state_only'=>true,'sale_status'=>1]]);
    $tenantPricing = Billing::display(Billing::prices($row, $tenant),$tenant);
    TenantPowerMarketService::savePrices($tenant,$product,[['sku_id'=>$sku,'state_only'=>true,'sale_status'=>0]]);
    $shelf = static fn() => Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>$tenant,'sku_id'=>$sku])->find();
    $paused = $shelf();
    TenantPowerMarketService::savePrices($tenant,$product,[['sku_id'=>$sku,'items_only'=>true,
        'item_prices'=>['input_images'=>['expected'=>$tenantPricing['items'][1]['sale'],'value'=>7]]]]);
    imageAssert($shelf()===$paused && (int)$paused['sale_status']===0,'tenant item-only setting preserves SKU state and scalar price');
    $decl['charge_items'][0]['rate'] = 2;
    $sync->invoke(null,$product,$decl,['resource'=>['type_code'=>'image','model_code'=>'billing-'.$nonce]]);
    $fresh = \app\common\model\power\PowerMarketSku::find($sku)->toArray();
    $price = Billing::prices($fresh,$tenant);
    imageAssert($price['items']['input_images']['rate']==='2.000000' && $price['items']['input_images']['platform']==='5.000000'
        && $price['items']['input_images']['tenant']==='7.000000','sync updates cost and preserves both manual per-image rates');
    $card = TenantPowerMarketService::models($tenant,'billing-'.$nonce,'',1,10,'image')['lists'][0] ?? [];
    imageAssert(!empty($card['has_image_billing_items']) && (int)$card['status']===0,
        'tenant can discover item settings even for a tenant-paused model');
    foreach ([
        ['sku_id'=>$sku,'items_only'=>true,'sale_status'=>1,'item_prices'=>['input_images'=>['expected'=>7,'value'=>8]]],
        ['sku_id'=>$sku,'items_only'=>true,'item_prices'=>['input_images'=>['expected'=>6,'value'=>8]]],
    ] as $invalid) {
        try {
            TenantPowerMarketService::savePrices($tenant,$product,[$invalid]);
            throw new RuntimeException('Invalid item-only write accepted');
        } catch (RuntimeException $error) {
            imageAssert(str_contains($error->getMessage(),'仅接受分项价格') || str_contains($error->getMessage(),'费率已更新'),
                'item-only write rejects mixed or stale updates');
        }
    }
    TenantPowerMarketService::savePrices($tenant,$product,[['sku_id'=>$sku,'items_only'=>true,
        'item_prices'=>['input_images'=>['expected'=>7,'value'=>null]]]]);
    imageAssert(Billing::prices($fresh,$tenant)['items']['input_images']['tenant']==='5.000000' && $shelf()===$paused,
        'restoring an item follows the current platform rate and preserves shelf state');

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
    PowerMarketService::savePrices($product,[['sku_id'=>$tokenSku,'items_only'=>true,
        'item_prices'=>['image_output'=>['expected'=>$platformToken['image_output']['sale'],'value'=>1200]]]]);
    $tenantToken = array_column(Billing::display(Billing::prices($tokenRow,$tenant),$tenant)['items'],null,'code');
    TenantPowerMarketService::savePrices($tenant,$product,[['sku_id'=>$tokenSku,'items_only'=>true,
        'item_prices'=>['image_output'=>['expected'=>$tenantToken['image_output']['sale'],'value'=>1350]]]]);
    imageAssert(!Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>$tenant,'sku_id'=>$tokenSku])->find(),
        'tenant item-only setting does not create a shelf override');
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

    $initial = wallets($tenant,$user);
    $number = 'LAYER'.$nonce;
    $appTask = AiAppTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'task_no'=>$number,'app_code'=>'aigc_image',
        'action_code'=>'generate','status'=>'running','idempotency_key'=>$number,'create_time'=>time(),'update_time'=>time()]);
    $imageTask = AigcImageTask::create(['app_task_id'=>$appTask['id'],'tenant_id'=>$tenant,'user_id'=>$user,
        'prompt'=>'Single input with two outputs','quantity'=>1,'provider'=>'power_market','status'=>'running','delete_time'=>0]);
    $layerSku = ['price'=>['points'=>14.256],'meter_summary'=>'生成张数','charge_base_mode'=>'add','charge_items'=>[
        ['code'=>'input_images','rate'=>'5.702400','unit'=>'input_image','included_count'=>0],
    ]];
    $layerPrice = Billing::prices(['id'=>0,'product_id'=>0,'source_payload'=>[
        'image_item_billing'=>Billing::declaration($layerSku,'seedream-5-5-layer-decomposition','official'),
    ]],$tenant,[],[]);
    $layerConsumption = AiConsumptionLog::create(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$appTask['id'],
        'consume_no'=>$number,'app_code'=>'aigc_image','action_code'=>'generate','resource_type'=>'model','provider'=>'power_market',
        'billing_status'=>'reserved','run_status'=>'reserved','reserved_tenant_cost'=>62.73,'reserved_user_price'=>62.73,
        'price_snapshot'=>['image_item_pricing'=>$layerPrice,'image_item_facts'=>['input_count'=>1,'output_limit'=>4],
            'requested_image_quantity'=>1,'usage_unit'=>'image'], 'quantity'=>1,'usage_unit'=>'image','create_time'=>time(),'update_time'=>time()]);
    PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,62.73,62.73,$number.'-reserve','local layer billing fixture');
    $layerImages = [['image_uri'=>'fixtures/base.png'],['image_uri'=>'fixtures/layer.png']];
    $settle->invoke(null,(int)$layerConsumption['id'],$layerImages,'','mock-layer-task',[]);
    $after = wallets($tenant,$user);
    imageAssert(round($initial[0]-$after[0],2)===34.21 && round($initial[1]-$after[1],2)===34.21,
        'layer task returns the unused reservation and charges only the actual output count');

    $resultQuery = static fn() => Db::name('aigc_image_result')->where('task_id',$imageTask['id']);
    $baseId = $resultQuery()->insertGetId(['task_id'=>$imageTask['id'],'tenant_id'=>$tenant,'user_id'=>$user,
        'image_uri'=>'fixtures/base.png','tenant_cost_points'=>17.11,'user_charge_points'=>17.11,'delete_time'=>0]);
    $imageTask->save(['status'=>'success','user_charge_points'=>17.11]);
    AigcImageService::syncMarketTaskResult($tenant,(int)$imageTask['id'],$user);
    imageAssert($resultQuery()->order('id')->column('image_uri')===['fixtures/base.png','fixtures/layer.png'],
        'settled layer result backfill completes a partial result from the frozen receipt');
    imageAssert(round((float)$resultQuery()->sum('user_charge_points'),2)===34.21
        && (float)AigcImageTask::find($imageTask['id'])['user_charge_points']===34.21,
        'per-result prices and task total agree at cent precision');
    $resultQuery()->where('id',$baseId)->update(['delete_time'=>time()]);
    AigcImageService::syncMarketTaskResult($tenant,(int)$imageTask['id'],$user);
    imageAssert($resultQuery()->count()===2 && $resultQuery()->where('delete_time',0)->count()===1 && wallets($tenant,$user)===$after,
        'repeated layer backfill neither charges again nor recreates a deleted result');
} finally {
    Db::rollback();
}
imageAssert(wallets($tenant,$user)===$before,'local fixture accounts rolled back');
