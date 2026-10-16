<?php
/** Read-only catalogue -> quote -> wire-payload audit. Never reserves, submits or polls. */
require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\power\{MarketTextModelRuntimeService as Text, MarketImageModelRuntimeService as Image, MarketVideoRuntimeService as Video, MarketMusicAppRuntimeService as Music};
use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService as Router;
use think\facade\Db;
$db = config('database.connections.mysql');
if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn','z_cn'], true)) throw new RuntimeException('Local catalogue only');
$tenant = (int)($argv[1] ?? 1);
$invoke = static fn($class,$method,...$args) => (new ReflectionMethod($class,$method))->invoke(null,...$args);
$report=['tenant_id'=>$tenant,'provider_calls'=>0,'writes'=>0,'entries'=>[]];
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $groups = ['text'=>['options'=>Text::textOptions($tenant)]] + Router::marketOverview($tenant);
    foreach ($groups as $type=>$group) foreach ($group['options'] as $option) {
        $entry=['type'=>$type,'id'=>$option['id'],'name'=>$option['name'] ?? $option['label'],'modes'=>$option['generation_modes'] ?? [],'checks'=>[]];
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
                    if ($type==='text') {
                        $model=Text::resolveModel($tenant,$option['id']);
                        $protocol=$invoke(Text::class,'protocolForModel',$model);
                        $payload=$invoke(Text::class,'requestPayload',$protocol,$model,[['role'=>'user','content'=>'offline contract fixture']],'',64,[]);
                    } elseif ($type==='image') {
                        $quote=Image::quote($tenant,$request,1);
                        $payload=$invoke(Image::class,'payload',$quote['market_snapshot'],$request,'offline-audit',0);
                    } elseif ($type==='music') {
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
                        $quote=Video::quote($tenant,$request);
                        $payload=$invoke(Video::class,$option['resource_type']==='model'?'modelPayload':'appPayload',$quote['market_snapshot'],$request,'offline-audit');
                    }
                    if (!$payload) throw new RuntimeException('Empty provider payload');
                    $check['passed']=true;
                    $check['payload_fields']=array_keys($payload);
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
} finally { Db::rollback(); }
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
