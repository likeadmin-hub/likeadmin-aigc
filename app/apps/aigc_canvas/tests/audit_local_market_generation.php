<?php
/** Read-only catalogue -> quote -> wire-payload audit. Never reserves, submits or polls. */
require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\power\{MarketTextModelRuntimeService as Text, MarketImageModelRuntimeService as Image, MarketVideoRuntimeService as Video, MarketMusicAppRuntimeService as Music, MarketNanoBananaAppRuntimeService as Nano};
use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService as Router;
use think\facade\Db;
use app\common\service\app\aigc_canvas\{CanvasGenerationParameters as Parameters, AigcCanvasService as Canvas};
use app\common\service\app\aigc_canvas\agent\prompt\{VideoPromptSubmissionService as Preflight, VideoPromptSpecCompiler as Compiler};
$db = config('database.connections.mysql');
if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) throw new RuntimeException('Local catalogue only');
$tenant = (int)($argv[1] ?? 1);
$invoke = static fn($class,$method,...$args) => (new ReflectionMethod($class,$method))->invoke(null,...$args);
$report=['tenant_id'=>$tenant,'provider_calls'=>0,'writes'=>0,'entries'=>[]];
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    \app\common\service\power\MarketCatalogReadScope::run(static function () use ($tenant, $invoke, &$report) {
    $groups = ['text'=>['options'=>Text::textOptions($tenant)]] + Router::marketOverview($tenant);
    foreach ($groups as $type=>$group) foreach ($group['options'] as $option) {
        $entry=['type'=>$type,'id'=>$option['id'],'name'=>$option['name'] ?? $option['label'],'modes'=>$option['generation_modes'] ?? [],'checks'=>[]];
        $entry['advanced_fields'] = Parameters::definitions($option, $type);
        $advanced = [];
        foreach ($entry['advanced_fields'] as $field) {
            if (!empty($field['locked'])) continue;
            $value = $field['default'] ?? ($field['options'][0] ?? null);
            if ($field['type'] === 'boolean') $value = false;
            elseif (in_array($field['type'], ['integer','number'], true) && $value === null) $value = max(0, $field['minimum'] ?? 0);
            elseif ($value === null) $value = $field['key'] === 'wm_url' ? 'https://example.test/watermark.png' : 'offline fixture';
            if ($field['key'] === 'max_tokens') $value = min(256, $field['maximum']);
            $advanced[$field['key']] = $value;
        }
        $skus = $option['skus'] ?? [$option];
        foreach ($skus as $sku) {
            $modes = $type === 'video' ? ($option['generation_modes'] ?? ['text_to_video']) : ['default'];
            // A SKU can be restricted to one input family; retain rejected combinations
            // in the report rather than treating every product mode as valid for every SKU.
            foreach ($modes as $mode) {
                $check=['sku_id'=>$sku['market_sku_id'] ?? 0,'mode'=>$mode];
                try {
                    $request=['prompt'=>'offline contract fixture','quantity'=>1,'market_product_id'=>$option['market_product_id'] ?? 0,
                        'market_sku_id'=>$sku['market_sku_id'] ?? 0,'channel'=>$option['id'],
                        'quality'=>$sku['quality'] ?? $sku['resolution'] ?? $option['default_quality'] ?? '',
                        'resolution'=>$sku['resolution'] ?? $option['default_resolution'] ?? '',
                        'duration'=>$sku['duration_options'][0] ?? $sku['duration'] ?? $option['default_duration'] ?? 5,
                        'ratio'=>$sku['ratio_options'][0] ?? $option['ratio_options'][0] ?? '16:9'];
                    if (empty($request['duration'])) $request['duration']=$option['default_duration'] ?? 5;
                    $request['advanced_params'] = $advanced;
                    $request = Parameters::apply($request, $option, $type);
                    if ($type==='text') {
                        $model=Text::resolveModel($tenant,$option['id']);
                        $protocol=$invoke(Text::class,'protocolForModel',$model);
                        $generation = $invoke(Text::class, 'generationParams', $model, $request['model_config'] ?? []);
                        $payload=$invoke(Text::class,'requestPayload',$protocol,$model,[['role'=>'user','content'=>'offline contract fixture']],'',$request['max_tokens'] ?? 64,$generation);
                    } elseif ($type==='image') {
                        $request = array_replace($request, $invoke(Canvas::class, 'normalizeImageParams', $request, 0, 0));
                        $runtime=Nano::isSelection($request) ? Nano::class : Image::class;
                        $quote=$runtime::quote($tenant,$request,1);
                        $payload=$invoke($runtime,'payload',$quote['market_snapshot'],$request,'offline-audit',0);
                    } elseif ($type==='music') {
                        $request = array_replace($request, $invoke(Canvas::class, 'normalizeMusicParams', $request));
                        $quote=Music::quote($tenant,$request);
                        $payload=$invoke(Music::class,'payload',$quote['market_snapshot'],$request+['custom'=>true,'instrumental'=>false,'lyrics'=>'offline lyrics','style'=>'piano'],'offline-audit');
                    } else {
                        $request['resource_type']=$option['resource_type'];
                        $request['generation_method']=$mode;
                        $assets=[];
                        if (in_array($mode,['image_to_video','start_end','image_reference','multi_frame','omni_reference','audio_reference'],true)) {
                            $assets[]=['type'=>'image','url'=>'https://example.test/first.jpg','role'=>in_array($mode,['image_to_video','start_end'],true)?'first_frame_image':'reference_image'];
                        }
                        if (in_array($mode,['start_end','multi_frame'],true)) $assets[]=['type'=>'image','url'=>'https://example.test/last.jpg','role'=>$mode==='start_end'?'last_frame_image':'reference_image'];
                        if ($mode==='video_edit') $assets[]=['type'=>'video','url'=>'https://example.test/video.mp4','role'=>'reference_video'];
                        if ($mode==='audio_reference') $assets[]=['type'=>'audio','url'=>'https://example.test/audio.mp3','role'=>'reference_audio'];
                        $request['reference_assets']=$assets;
                        // Match the node's compatibility aliases, which previously double-counted Q4's one image.
                        $request['reference_images'] = array_column(array_filter($assets, fn($asset) => $asset['type'] === 'image'), 'url');
                        foreach ($assets as $asset) if (in_array($asset['role'], ['first_frame_image','last_frame_image'], true)) $request[$asset['role']] = $asset['url'];
                        $compiled = Compiler::compile($request);
                        $preflight = array_replace($request, ['prompt'=>$compiled['compiled_prompt'], 'prompt_spec_json'=>$compiled['prompt_spec_json'], 'compiler_version'=>$compiled['compiler_version'], 'prompt_hash'=>$compiled['prompt_hash']]);
                        $invoke(Preflight::class, 'preflight', $tenant, $preflight);
                        $request = array_replace($request, $invoke(Canvas::class, 'normalizeVideoParams', $request, 0, 0));
                        $quote=Video::quote($tenant,$request);
                        $payload=$invoke(Video::class,$option['resource_type']==='model'?'modelPayload':'appPayload',$quote['market_snapshot'],$request,'offline-audit');
                    }
                    if (!$payload) throw new RuntimeException('Empty provider payload');
                    $check['passed']=true;
                    $check['payload_fields']=array_keys($payload);
                    $check['advanced_fields']=[];
                    foreach ($advanced as $key=>$value) {
                        $actual = $payload;
                        foreach (explode('.', $key) as $part) $actual = is_array($actual) ? ($actual[$part] ?? null) : null;
                        // Text protocols rename token limits; validated by the text protocol contract suite.
                        if ($type === 'text' && $key === 'max_tokens') $actual = $payload['max_tokens'] ?? $payload['max_completion_tokens'] ?? $payload['max_output_tokens'] ?? null;
                        if ($key === 'wm_position' || $key === 'wm_url') { if (($payload['watermark'] ?? true) === false) continue; }
                        if ($actual === null || $actual != $value) throw new RuntimeException('Advanced option lost or changed: '.$key);
                        $check['advanced_fields'][]=$key;
                    }
                } catch (Throwable $e) {
                    $check['passed']=false;
                    $check['reason']=$e->getMessage();
                }
                $entry['checks'][]=$check;
            }
        }
        $entry['passed_checks']=count(array_filter($entry['checks'],fn($row)=>$row['passed']));
        $entry['rejected_checks']=count($entry['checks'])-$entry['passed_checks'];
        $report['entries'][]=$entry;
    }
    });
} finally { Db::rollback(); }
$report['checked_entries']=count($report['entries']);
$report['checked_combinations']=array_sum(array_map(fn($entry)=>count($entry['checks']),$report['entries']));
$report['rejected_combinations']=array_sum(array_column($report['entries'],'rejected_checks'));
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
exit($report['checked_entries'] > 0 && $report['rejected_combinations'] === 0 ? 0 : 1);
