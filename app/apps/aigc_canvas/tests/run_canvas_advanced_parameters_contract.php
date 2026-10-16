<?php
/** Deterministic Canvas -> normalizer -> provider payload tests. No database or network. */
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\{CanvasGenerationParameters as Parameters, AigcCanvasService as Canvas, CanvasRequestInput};
use app\common\service\app\aigc_canvas\agent\prompt\VideoPromptSubmissionService as Preflight;
use app\common\service\power\{MarketVideoRuntimeService as Video, MarketImageModelRuntimeService as Image, MarketMusicAppRuntimeService as Music, MarketTextModelRuntimeService as Text, Q4VideoContract};

$checks = 0;
$check = static function ($condition, $message) use (&$checks) { $checks++; if (!$condition) throw new RuntimeException($message); };
$call = static fn($class, $method, ...$args) => (new ReflectionMethod($class, $method))->invoke(null, ...$args);
$base = ['channel_code'=>'fixture', 'product_id'=>1, 'sku_id'=>2, 'sku_key'=>'fixture', 'locked_params'=>[], 'default_params'=>[]];
$schema = ['seed'=>['type'=>'integer','minimum'=>0], 'audio'=>['type'=>'boolean'], 'is_rec'=>['type'=>'boolean'], 'watermark'=>['type'=>'boolean'], 'wm_position'=>['type'=>'integer','enum'=>[1,2,3,4]]];
$q4 = array_replace($base, ['model_code'=>'q4-preview-video','params_schema'=>$schema]);
$request = ['prompt'=>'A dog runs', 'quality'=>'720p', 'duration'=>5, 'reference_images'=>['https://example.test/dog.jpg'],
    'reference_assets'=>[['type'=>'image','uri'=>'https://example.test/dog.jpg','role'=>'first_frame_image']], 'first_frame_image'=>'https://example.test/dog.jpg',
    'advanced_params'=>['seed'=>0,'audio'=>false,'is_rec'=>false,'watermark'=>false]];
$httpRequest = (new app\Request())->withPost($request);
$typed = CanvasRequestInput::post($httpRequest);
$check($typed['advanced_params']['audio'] === false && $typed['advanced_params']['seed'] === 0, 'HTTP input filter coerced JSON false/zero');
$prepared = Parameters::apply($typed, $q4, 'video');
$normalized = $call(Canvas::class, 'normalizeVideoParams', $prepared, 0, 0);
$normalized['provider_params'] = $prepared['provider_params'];
$payload = $call(Video::class, 'modelPayload', $q4, $normalized, 'offline');
$check($payload['seed'] === 0 && $payload['audio'] === false && $payload['is_rec'] === false && $payload['watermark'] === false, 'Q4 options lost between Canvas and wire payload');
$check(count($call(Preflight::class, 'assets', $request)['image']) === 1, 'One Q4 image counted multiple times across aliases');
$request['reference_images'][] = 'https://example.test/other.jpg';
$check(count($call(Preflight::class, 'assets', $request)['image']) === 2, 'Distinct references were accidentally collapsed');
$check(count($call(Preflight::class, 'assets', ['image_urls'=>['https://example.test/a.jpg']])['image']) === 1, 'Legacy image_urls lost');
$sameFrames = ['first_frame_image'=>'https://example.test/a.jpg','last_frame_image'=>'https://example.test/a.jpg'];
$check(count($call(Preflight::class, 'assets', $sameFrames)['image']) === 2, 'Frame role identity lost');

foreach ([['seed'=>-1],['seed'=>1.5],['audio'=>'nope'],['wm_position'=>5],['callback_url'=>'https://example.test'],['market_sku_id'=>99]] as $invalid) {
    try { Parameters::apply(['advanced_params'=>$invalid], $q4, 'video'); $check(false, 'Invalid/transport option accepted'); }
    catch (InvalidArgumentException) { $check(true, 'Rejected'); }
}
$locked = array_replace($q4, ['locked_params'=>['seed'=>8]]);
try { Parameters::apply(['advanced_params'=>['seed'=>7]], $locked, 'video'); $check(false, 'SKU lock bypassed'); }
catch (InvalidArgumentException) { $check(true, 'Locked'); }

$image = array_replace($base, ['model_code'=>'fixture-image','params_schema'=>['prompt'=>['type'=>'string'],'quality'=>['type'=>'string','enum'=>'["low","high"]'],'resolution'=>['type'=>'string'],'background'=>['type'=>'string','enum'=>['transparent']]]]);
$image['market_metadata'] = ['params_schema'=>$image['params_schema']];
$request = Parameters::apply(['prompt'=>'fixture','quality'=>'1k','advanced_params'=>['quality'=>'high','background'=>'transparent']], $image, 'image');
$normalized = $call(Canvas::class, 'normalizeImageParams', $request, 0, 0);
$normalized['provider_params'] = $request['provider_params'];
$payload = $call(Image::class, 'payload', $image, $normalized, 'offline');
$check($payload['quality'] === 'high' && $payload['resolution'] === '1k' && $payload['background'] === 'transparent', 'Image rendering quality changed billing resolution or lost background');

$structured = array_replace($base, ['model_code'=>'wan3.0-video','params_schema'=>['input'=>['type'=>'object'],'parameters'=>['type'=>'object','example'=>['audio'=>'1','watermark'=>'']]]]);
$request = Parameters::apply(['prompt'=>'fixture','quality'=>'720p','duration'=>5,'advanced_params'=>['parameters.audio'=>false,'parameters.watermark'=>false]], $structured, 'video');
$normalized = $call(Canvas::class, 'normalizeVideoParams', $request, 0, 0);
$normalized['provider_params'] = $request['provider_params'];
$payload = $call(Video::class, 'modelPayload', $structured, $normalized, 'offline');
$check($payload['parameters']['audio'] === false && $payload['parameters']['watermark'] === false, 'Structured video nesting or false values lost');

$music = array_replace($base, ['locked_params'=>['type'=>'generate'], 'params_schema'=>['properties'=>['weirdness'=>['type'=>'number','minimum'=>0,'maximum'=>1],'style_influence'=>['type'=>'number','minimum'=>0,'maximum'=>1],'lyric_prompt'=>['type'=>'string']]]]);
$request = Parameters::apply(['custom'=>true,'instrumental'=>false,'advanced_params'=>['weirdness'=>0,'style_influence'=>0.75,'lyric_prompt'=>'海边']], $music, 'music');
$normalized = $call(Canvas::class, 'normalizeMusicParams', $request);
$normalized['provider_params'] = $request['provider_params'];
$payload = $call(Music::class, 'payload', $music, $normalized, 'offline');
$check($payload['weirdness'] === 0.0 && $payload['style_influence'] === 0.75 && $payload['lyric_prompt'] === '海边' && $payload['custom'] === true, 'Music advanced fields lost');
try { Parameters::apply(['advanced_params'=>['weirdness'=>1.1]], $music, 'music'); $check(false, 'Music bound ignored'); }
catch (InvalidArgumentException) { $check(true, 'Bounded'); }

$text = ['max_output_tokens'=>4096,'params_schema'=>['temperature'=>['type'=>'number','minimum'=>0,'maximum'=>2],'enable_thinking'=>['type'=>'boolean']]];
$request = Parameters::apply(['advanced_params'=>['max_tokens'=>256,'temperature'=>0,'enable_thinking'=>false]], $text, 'text');
$generation = $call(Text::class, 'generationParams', $text, $request['model_config']);
$check($request['max_tokens'] === 256 && $generation['temperature'] === 0.0 && $generation['enable_thinking'] === false, 'Text overrides lost');
$savedCanvas = CanvasRequestInput::post((new app\Request())->withPost(['nodes'=>[['metadata'=>['generationParameters'=>['q4'=>['audio'=>false,'seed'=>0]], 'unknownBoolean'=>false,'prompt'=>' trim me ']]]]));
$check($savedCanvas['nodes'][0]['metadata']['generationParameters']['q4'] === ['audio'=>false,'seed'=>0] && $savedCanvas['nodes'][0]['metadata']['unknownBoolean'] === false, 'Saving canvas lost nested JSON types');
$check($savedCanvas['nodes'][0]['metadata']['prompt'] === 'trim me', 'Canvas string trimming changed');
$legacy = ['channel'=>'old','unknown_metadata'=>['preserve'=>true]];
$check(Parameters::apply($legacy, [], 'image') === $legacy, 'Legacy request changed without advanced parameters');
$grok = ['resource_type'=>'app_api', 'upstream_app_code'=>'grok_video'];
$meta = ['generation_modes'=>['text_to_video','image_reference','video_edit','audio_reference'], 'supported_asset_types'=>['image','video','audio']];
$check(!in_array('video_edit', $call(Video::class, 'generationModes', $grok, $meta), true), 'Grok advertised an unimplemented video input');
$check($call(Video::class, 'supportedAssetTypes', $grok, $meta) === ['image'], 'Grok accepts a modality not encoded by its adapter');
try { $call(Video::class, 'appPayload', $base + ['app_code'=>'grok_video'], ['reference_assets'=>[['type'=>'video','url'=>'https://example.test/a.mp4']]], 'offline'); $check(false, 'Grok silently discarded video'); }
catch (Exception $e) { $check(str_contains($e->getMessage(), '仅支持参考图片'), 'Wrong Grok rejection'); }
echo "Canvas advanced parameter contract: {$checks} checks passed; no database, writes or provider calls\n";
