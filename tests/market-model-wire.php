<?php
// Reproduce the /api/v1/tasks controller's nested-params extraction, before any
// provider normalizer can silently discard unsupported business parameters.
$source = getenv('MARKET_MODEL_WIRE_SOURCE') ?: dirname(__DIR__).'/app/common/service/power/MarketModelTaskRequest.php';
$flat = ['market_product_id'=>778,'market_sku_id'=>91,'sku_id'=>91,'market_sku_key'=>'sku','pricing_sku_key'=>'sku','price_source'=>'power_market_sku','sku_key'=>'sku','model'=>'public-model','channel'=>'official','idempotency_key'=>'local-attempt','prompt'=>'a cat','seed'=>0,'audio'=>false,'image_urls'=>['https://example.com/cat.png']];
$wire = $flat;
if (is_file($source)) { require $source; $wire = \app\common\service\power\MarketModelTaskRequest::payload($flat); }
$business = isset($wire['params']) ? $wire['params'] : array_diff_key($wire,array_flip(['model','channel','callback_url','storage']));
$allowed = ['sku_key','prompt','seed','audio','image_urls'];
$unknown = array_diff(array_keys($business),$allowed);
if ($unknown) throw new RuntimeException('Strict provider rejects: '.implode(', ',$unknown));
if ($business !== array_intersect_key($flat,array_flip($allowed))) throw new RuntimeException('Native inputs changed');
if (isset($wire['market_product_id']) || isset($wire['price_source'])) throw new RuntimeException('Local billing context leaked');
if (($wire['model']??'') !== 'public-model' || ($wire['channel']??'') !== 'official') throw new RuntimeException('Model routing lost');
// SmartRouteExecutor receives the task envelope before the ordinary controller
// extracts legacy nested params. Its adapter must see native input at top level.
foreach (['gpt-image-2','gpt-image-2.5','gpt-image-2-fast'] as $model) {
    $input = array_replace($flat, ['model'=>$model,'resolution'=>'2k','aspect_ratio'=>'1:1']);
    $candidate = \app\common\service\power\MarketModelTaskRequest::payload($input);
    if (($candidate['prompt']??'') !== 'a cat' || ($candidate['resolution']??'') !== '2k' || ($candidate['aspect_ratio']??'') !== '1:1') throw new RuntimeException('Smart route cannot read top-level image parameters');
    if (($candidate['image_urls']??[]) !== $flat['image_urls']) throw new RuntimeException('Smart route references changed');
    if (isset($candidate['params'])) throw new RuntimeException('Task input was hidden inside legacy params');
}
$nested = \app\common\service\power\MarketModelTaskRequest::payload(['model'=>'public-model','channel'=>'official','params'=>$flat]);
if ($nested !== $wire) throw new RuntimeException('Legacy nested input does not normalize to the same envelope');
echo "PASS strict submit rejects no local context; routing, SKU and typed native parameters preserved\n";
