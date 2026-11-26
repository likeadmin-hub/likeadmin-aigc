<?php
declare(strict_types=1);

use app\common\service\app\aigc_product_video\{ProductVideoPolicy as Policy, ProductVideoImport, ProductVideoMaterials, ProductVideoPrompts, ProductVideoTemplates, ProductVideoWorker, SaleVideoWorkflow};
use app\common\service\power\MarketVideoAudioOutput;

require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
function expectSale(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

Policy::forSale(function () {
    $source = ['images'=>[['uri'=>'watch.jpg']], 'normal_source'=>'template', 'language'=>'English',
        'market_region'=>'US', 'audio'=>false, 'prompt'=>'@图片2', 'mode'=>'advanced', 'video_selection'=>['duration'=>10]];
    $ugc = Policy::body($source);
    expectSale($ugc['mode'] === 'normal' && $ugc['audio'] && $ugc['template_code'] === 'ugc', 'UGC defaults');
    $direction = Policy::prompt($ugc);
    $writer = ProductVideoPrompts::system('write');
    foreach ([SaleVideoWorkflow::DIRECTION, SaleVideoWorkflow::UGC] as $instruction) {
        expectSale(str_contains($writer, $instruction) && str_contains($direction, $instruction), 'Writing and UGC rendering must share presenter and demonstration instructions');
    }
    expectSale(str_contains($direction, '10 秒') && str_contains($writer, 'duration_seconds'), 'Both stages receive timing instructions');
    expectSale(str_contains($direction,'English') && str_contains($direction,'第一人称') && !str_contains($direction,'@图片2'), 'UGC speech');
    expectSale(!str_contains($direction, "\n"), 'UGC prompt must satisfy single-line video contracts');
    ProductVideoMaterials::assertMentions($ugc, $ugc['images']);
    expectSale(ProductVideoTemplates::snapshot($ugc)['normal_source'] === 'template', 'UGC template round trip');
    $custom = Policy::body(array_replace($source, ['normal_source'=>'prompt', 'prompt'=>'Show the dial', 'prompt_review_images'=>['watch.jpg']]));
    expectSale(str_contains(Policy::prompt($custom), 'Show the dial'), 'custom speech');
    expectSale(!str_contains(Policy::prompt($custom), SaleVideoWorkflow::UGC), 'A custom script supplies its own wording and sequence');
    $write = ['action'=>'write', 'request_json'=>Policy::encode(['duration'=>2, 'snapshot'=>[
        'images'=>[['reference_slot_id'=>'slot-1','mention_name'=>'图片1']],
    ]])];
    $canonical = "抬手。\nLook here.";
    expectSale(ProductVideoWorker::parse($write, ['content'=>Policy::encode(['video_prompt'=>$canonical, 'duration_seconds'=>2])])
        === ['video_prompt'=>$canonical], 'Canonical scripts stay editable and verbatim');
    $structured = ['video_title'=>'Pocket speaker', 'production_notes'=>['lighting_style'=>'Sunlight'], 'shots'=>[
        ['start_time'=>0,'end_time'=>4,'visual_description'=>'Hold it up','spoken_dialogue_or_voiceover_script'=>'Hear this?',
            'source_slot_ids'=>['slot-1','unknown']],
        ['start_time'=>4,'end_time'=>30,'camera_movement'=>'Pan','on_screen_text'=>'Hi','spoken_dialogue_or_voiceover_script'=>'Take it along.'],
    ]];
    $result = ProductVideoWorker::parse($write, ['content'=>"```json\n".Policy::encode($structured)."\n```"]);
    foreach (['Pocket speaker','Sunlight','0–4 秒','4–30 秒','@图片1','Hold it up','Hear this?','Pan','Hi','Take it along.'] as $part) {
        expectSale(str_contains($result['video_prompt'], $part), 'Structured script lost '.$part);
    }
    expectSale(!str_contains($result['video_prompt'], 'unknown') && str_contains($result['notice'], '30 秒')
        && str_contains($result['notice'], '2 秒'), 'Respect the frozen image slots and distinguish the requested duration');
    foreach (['{broken', '{"video_prompt":42}', '{"video_title":"Only a title"}', '{"shots":[{"description":"No speech"}]}'] as $invalid) {
        try { ProductVideoWorker::parse($write, ['content'=>$invalid]); }
        catch (RuntimeException $error) { expectSale($error->getMessage() === SaleVideoWorkflow::SCRIPT_FORMAT_ERROR, 'Format error'); continue; }
        throw new RuntimeException('Incomplete script accepted');
    }
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
expectSale(!str_contains(ProductVideoPrompts::system('write'), SaleVideoWorkflow::DIRECTION), 'Other tools keep their own presenter rules');
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
