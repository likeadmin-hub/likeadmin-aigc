<?php
require dirname(__DIR__).'/vendor/autoload.php';

use app\common\service\power\MarketImageItemBilling as Billing;
use app\common\service\power\MarketImageModelRuntimeService as Runtime;

function requireEqual(mixed $expected, mixed $actual): void
{
    if ($actual !== $expected) throw new RuntimeException(json_encode([$expected, $actual]));
}

$image = ['price'=>['points'=>16], 'meter_summary'=>'按次', 'charge_base_mode'=>'add', 'charge_items'=>[
    ['code'=>'input_images','rate'=>'2','unit'=>'input_image','included_count'=>1],
]];
$contract = Billing::declaration($image, 'official-33-7b77ec425b57b520cd');
$sku = ['id'=>8, 'product_id'=>4, 'source_payload'=>['image_item_billing'=>$contract]];
$platform = [['scope_type'=>'global','scope_id'=>0,'mode'=>'percent','value'=>'25']];
$tenant = [['scope_type'=>Billing::scope('input_images'),'scope_id'=>8,'mode'=>'manual','value'=>'4']];
$pricing = Billing::prices($sku, 9, $platform, $tenant);
requireEqual([25.0,28.0], Billing::amounts($pricing, ['input_count'=>3], 2));
requireEqual(3, Billing::inputCount($contract, ['image_urls'=>['a','b','a']]));
requireEqual([20.0,20.0], Billing::amounts($pricing, ['input_count'=>1], 2));

foreach (['1k'=>7.128, '2k'=>7.128, '4k'=>21.78] as $tier => $outputRate) {
    $h3 = [
        'sku_key'=>$tier, 'price'=>['points'=>$outputRate], 'meter_summary'=>'按次',
        'charge_base_mode'=>'add', 'charge_items'=>[
            ['code'=>'input_images','rate'=>'1.98','unit'=>'input_image','included_count'=>0],
        ],
    ];
    $h3Contract = Billing::declaration($h3, 'minimax-h3-image-edit', 'official');
    requireEqual(Billing::declaration($h3, 'official-33-7b77ec425b57b520cd'), $h3Contract);
    $h3Pricing = Billing::prices(['id'=>8, 'product_id'=>4, 'source_payload'=>['image_item_billing'=>$h3Contract]], 0, [], []);
    $facts = Billing::facts($h3Pricing, ['image_urls'=>['first', 'second'], 'style_image'=>'irrelevant']);
    requireEqual(['input_count'=>2], $facts);
    $total = round($outputRate + 2 * 1.98, 6);
    requireEqual([$total, $total], Billing::amounts($h3Pricing, $facts, 1));
}

$seedreamEdit = ['price'=>['points'=>14.256], 'meter_contract'=>['basis'=>'image'], 'charge_base_mode'=>'add', 'charge_items'=>[
    ['code'=>'input_images','rate'=>'5.702400','unit'=>'input_image','included_count'=>0],
]];
$seedreamContract = Billing::declaration($seedreamEdit, 'seedream-5-5-image-to-image', 'official');
requireEqual(['image_urls'], $seedreamContract['bindings']);
$seedreamPricing = Billing::prices(['id'=>8, 'product_id'=>4, 'source_payload'=>['image_item_billing'=>$seedreamContract]], 0, [], []);
$seedreamFacts = Billing::facts($seedreamPricing, ['image_urls'=>['reference.png'], 'image_url'=>'unrelated.png']);
requireEqual(['input_count'=>1], $seedreamFacts);
requireEqual([19.9584, 19.9584], Billing::amounts($seedreamPricing, $seedreamFacts, 1));
try {
    Billing::declaration($seedreamEdit, 'seedream-5-5-image-to-image', 'third-party');
    throw new RuntimeException('An unrelated channel inherited the official Seedream binding');
} catch (InvalidArgumentException $error) {
    requireEqual('该规格缺少已核验的输入图片字段', $error->getMessage());
}

$seedreamLayers = Billing::declaration($seedreamEdit, 'seedream-5-5-layer-decomposition', 'official');
requireEqual(['image_url'], $seedreamLayers['bindings']);
$layerPricing = Billing::prices(['id'=>8, 'product_id'=>4, 'source_payload'=>['image_item_billing'=>$seedreamLayers]], 0, [], []);
$layerPayload = ['image_url'=>'https://example.test/base.png','max_images'=>4];
$layerFacts = Billing::facts($layerPricing, $layerPayload);
requireEqual(['input_count'=>1,'output_limit'=>4], $layerFacts);
requireEqual(['input_count'=>1,'output_limit'=>30], Billing::facts($layerPricing, ['image_url'=>$layerPayload['image_url']]));
foreach ([1, 101, 2.5] as $invalid) {
    try {
        Billing::facts($layerPricing, array_replace($layerPayload, ['max_images'=>$invalid]));
        throw new RuntimeException('Invalid Seedream layer limit accepted');
    } catch (InvalidArgumentException $error) {
        requireEqual('最大输出张数须为 2 至 100 的整数', $error->getMessage());
    }
}
try {
    Billing::facts($layerPricing, ['image_urls'=>[$layerPayload['image_url']], 'max_images'=>4]);
    throw new RuntimeException('A different image field was counted as the layer source');
} catch (InvalidArgumentException $error) {
    requireEqual('图层拆分需要 1 张输入图', $error->getMessage());
}
$quoteMarket = new ReflectionMethod(Runtime::class, 'quoteMarket');
$layerQuote = $quoteMarket->invoke(null, ['tenant_price'=>14.256, 'image_item_pricing'=>$layerPricing, 'image_item_facts'=>$layerFacts,
    'product'=>['id'=>4,'upstream_model_code'=>'seedream-5-5-layer-decomposition','upstream_channel_code'=>'official'],
    'sku'=>['id'=>8,'usage_unit'=>'image','usage_unit_size'=>1,'upstream_price'=>14.256,'sale_points'=>14.256,'sku_key'=>'seedream55-1k'], 'reference_limit'=>1], 1, []);
requireEqual(1, $layerQuote['quantity']);
requireEqual(4, $layerQuote['billing_details']['output_limit']);
requireEqual(62.7264, $layerQuote['tenant_cost_points']);
requireEqual(62.7264, $layerQuote['user_charge_points']);
requireEqual([34.2144,34.2144], Billing::amounts($layerPricing, $layerFacts, 2));

$changed = $image;
$changed['charge_items'][0]['rate'] = '4';
$sku['source_payload']['image_item_billing'] = Billing::declaration($changed, 'official-33-7b77ec425b57b520cd');
requireEqual(false, Billing::prices($sku, 9, $platform, $tenant)['valid']);
requireEqual([25.0,28.0], Billing::amounts($pricing, ['input_count'=>3], 2));

$token = ['price'=>['points'=>0], 'charge_base_mode'=>'replace', 'reserve_upper_points'=>'12', 'charge_items'=>[]];
foreach (['text_input_uncached','text_input_cached','image_input_uncached','image_input_cached','image_output'] as $code) {
    $token['charge_items'][]=['code'=>$code,'rate'=>'1000','unit'=>'token','unit_size'=>1000000];
}
$sku['source_payload']['image_item_billing']=Billing::declaration($token, 'test-model');
$pricing = Billing::prices($sku, 9, $platform, []);
requireEqual([15.0, 15.0], Billing::amounts($pricing, [], 1));
$receipt = ['token_billing'=>['status'=>'settled','actual_points'=>'1','items'=>array_map(static fn($item)=>[
    'code'=>$item['code'],'tokens'=>$item['code']==='image_output'?1000:0,
    'points_per_million_tokens'=>'1000',
],$token['charge_items'])]];
requireEqual([1.25,1.25], Billing::amounts($pricing, [], 1, $receipt));
requireEqual(null, Billing::receipt($pricing, ['token_billing'=>['status'=>'reconciliation_pending']], []));
requireEqual(null, Billing::receipt($pricing, ['token_billing'=>['status'=>'settled','actual_points'=>'1','items'=>[]]], []));
$long = $image + ['sku_key'=>'webpage-1k'];
$longContract = Billing::declaration($long, 'official-33-42b1deb0efa8cdf52d');
requireEqual($longContract, Billing::declaration($long, 'kling-4-0-long-image', 'official'));
requireEqual(['image_urls'], Billing::declaration($image + [
    'sku_key'=>'ecommerce-long-2k', 'locked_params'=>['skill'=>'ecommerce'],
], 'kling-4-0-long-image', 'official')['bindings']);
requireEqual(2, Billing::inputCount($longContract, ['style_image'=>'style','subject_image'=>'subject','image_urls'=>['extra']]));
$kling = $image;
$kling['meter_summary'] = '生成张数';
$klingContract = Billing::declaration($kling,'official-33-f9a445be9ef6a7f58d');
requireEqual($klingContract, Billing::declaration($kling, 'kling-4-0-image', 'official'));
try {
    Billing::declaration($kling, 'kling-4-0-image', 'third-party');
    throw new RuntimeException('An unrelated channel inherited the official image binding');
} catch (InvalidArgumentException $error) {
    requireEqual('该规格缺少已核验的输入图片字段', $error->getMessage());
}
requireEqual(3, Billing::inputCount($klingContract, ['image_urls'=>['asset'],'elements'=>[['name'=>'a'],['name'=>'b']]]));
$klingPricing = Billing::prices(['product_id'=>4,'id'=>8,'source_payload'=>['image_item_billing'=>$klingContract]],9,[],[]);
requireEqual([36.0,36.0], Billing::amounts($klingPricing, ['input_count'=>3], 2));
requireEqual([20.0,20.0], Billing::amounts($klingPricing, ['input_count'=>3], 1));
$sku['source_payload']['image_item_billing']['cap'] = '0.999999';
requireEqual(null,Billing::receipt($sku['source_payload']['image_item_billing'],$receipt,[]));

$credit = $token;
unset($credit['reserve_upper_points']);
foreach (['3564', '891', '5702.4', '1425.6', '21384'] as $i => $rate) $credit['charge_items'][$i]['rate'] = $rate;
foreach (['gpt-image-2-6-flare', 'gpt-image-2-6-sunburst'] as $model) {
    $creditContract = Billing::declaration($credit, $model, 'official');
    requireEqual('1188.000000', $creditContract['cap']);
    $sku['source_payload']['image_item_billing'] = $creditContract;
    requireEqual([1188.0, 1188.0], Billing::amounts(Billing::prices($sku, 0, [], []), [], 1));
}

echo "PASS image item preview, tier pricing, preserved manual price, immutable snapshot and Token receipts\n";
