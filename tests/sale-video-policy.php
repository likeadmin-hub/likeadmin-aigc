<?php
declare(strict_types=1);

use app\common\service\app\aigc_product_video\{ProductVideoPolicy as Policy, ProductVideoImport, ProductVideoMaterials, ProductVideoTemplates, SaleVideoWorkflow};
use app\common\service\power\MarketVideoAudioOutput;

require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
function expectSale(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

Policy::forSale(function () {
    $source = ['images'=>[['uri'=>'watch.jpg']], 'normal_source'=>'template', 'language'=>'English',
        'market_region'=>'US', 'audio'=>false, 'prompt'=>'@图片2', 'mode'=>'advanced'];
    $ugc = Policy::body($source);
    expectSale($ugc['mode'] === 'normal' && $ugc['audio'] && $ugc['template_code'] === 'ugc', 'UGC defaults');
    $direction = Policy::prompt($ugc);
    expectSale(str_contains($direction,'English') && str_contains($direction,'第一人称') && !str_contains($direction,'@图片2'), 'UGC speech');
    expectSale(!str_contains($direction, "\n"), 'UGC prompt must satisfy single-line video contracts');
    ProductVideoMaterials::assertMentions($ugc, $ugc['images']);
    expectSale(ProductVideoTemplates::snapshot($ugc)['normal_source'] === 'template', 'UGC template round trip');
    $custom = Policy::body(array_replace($source, ['normal_source'=>'prompt', 'prompt'=>'Show the dial', 'prompt_review_images'=>['watch.jpg']]));
    expectSale(str_contains(Policy::prompt($custom), 'Show the dial'), 'custom speech');
    $custom['prompt'] = "Show the dial.\nTilt the watch.";
    expectSale(str_contains(Policy::prompt($custom), 'Show the dial. Tilt the watch.') && str_contains($custom['prompt'], "\n"), 'Preserve the editable multiline script');
    expectSale(ProductVideoImport::capacity(['max_reference_images'=>15]) === 10, 'sale model limit');
    expectSale(SaleVideoWorkflow::target('https://shop.tiktok.com/view/product/12345?utm_campaign=shop')['url'] === 'https://shop.tiktok.com/view/product/12345', 'product URL');
    expectSale(SaleVideoWorkflow::target('https://www.tiktok.com/shop/pdp/watch/12345')['provider'] === 'tiktok', 'US shop URL');
    expectSale(SaleVideoWorkflow::target('https://www.amazon.com/dp/B000000001')['asin'] === 'B000000001', 'Amazon SKU');
    try { SaleVideoWorkflow::target('https://www.tiktok.com/@user/video/12345'); throw new RuntimeException('personal video accepted'); }
    catch (InvalidArgumentException $expected) {}
    $session = ['provider'=>'tiktok','phase'=>'page','status'=>'pending','source_url'=>'https://shop.tiktok.com/view/product/12345','maximum'=>2];
    $next = ProductVideoImport::advance($session, fn($url) => ['url'=>$url,'body'=>'<meta property="og:title" content="Watch"><meta property="og:image" content="https://example.com/watch.jpg">'], fn()=>[]);
    expectSale($next['phase']==='images' && $next['name']==='Watch' && count($next['candidates'])===1,'TikTok product metadata');
});
expectSale(Policy::app()===Policy::APP && ProductVideoImport::capacity(['max_reference_images'=>15])===15,'product app scope restored');
foreach (['sound', 'enable_audio', 'generate_audio_switch', 'audio', 'generate_audio'] as $field) {
    $snapshot = ['params_schema'=>[$field=>['type'=>'boolean', 'default'=>false]]];
    $request = MarketVideoAudioOutput::enable($snapshot, []);
    expectSale($request[$field] === true, 'Native audio switch: '.$field);
    MarketVideoAudioOutput::assertEnabled([$field=>$request[$field]]);
    try {
        MarketVideoAudioOutput::enable($snapshot + ['locked_params'=>[$field=>false]], []);
        throw new RuntimeException('Silent SKU accepted: '.$field);
    } catch (InvalidArgumentException $expected) {}
}
MarketVideoAudioOutput::assertEnabled(['parameters'=>['audio'=>true]]);
expectSale(MarketVideoAudioOutput::field(['audio'=>['type'=>'object'], 'keep_original_sound'=>['type'=>'boolean']]) === null, 'Input audio and retained soundtrack do not enable output');
try {
    MarketVideoAudioOutput::assertEnabled(['enable_audio'=>false, 'keep_original_sound'=>true]);
    throw new RuntimeException('Silent native payload accepted');
} catch (InvalidArgumentException $expected) {}
echo "Sale video policy, speech, imports and isolation passed\n";
