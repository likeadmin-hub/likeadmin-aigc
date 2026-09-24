<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_video\AigcVideoService;

/** Read-only catalogue and quote validation. Never reserves points or submits a Provider task. */
function fixtureReferences(string $mode, bool $audioRequiresVisual): array
{
    $image=static fn(int $index,string $role): array => ['type'=>'image','url'=>'https://fixture.invalid/image-'.$index.'.png','role'=>$role];
    $video=static fn(): array => ['type'=>'video','url'=>'https://fixture.invalid/video.mp4','role'=>'reference_video'];
    $audio=static fn(): array => ['type'=>'audio','url'=>'https://fixture.invalid/audio.mp3','role'=>'reference_audio'];
    return match ($mode) {
        'image_to_video'=>[$image(1,'first_frame_image')],
        'start_end'=>[$image(1,'first_frame_image'),$image(2,'last_frame_image')],
        'image_reference'=>[$image(1,'reference_image')],
        'multi_frame'=>[$image(1,'reference_image'),$image(2,'reference_image')],
        'video_edit'=>[$video()],
        'audio_reference'=>$audioRequiresVisual?[$image(1,'reference_image'),$audio()]:[$audio()],
        'omni_reference'=>[$image(1,'reference_image')],
        default=>[],
    };
}

$catalog=AigcVideoService::marketOptions(1);
$options=array_values(array_filter((array)($catalog['channels']??[]),static fn(array $option): bool => !empty($option['available'])));
$checked=0;$errors=[];
foreach ($options as $option) {
    $modes=array_values(array_unique((array)($option['generation_modes']??[])));
    foreach ($modes as $mode) {
        $params=[
            'model_id'=>(string)$option['id'],
            'market_product_id'=>(int)$option['market_product_id'],
            'generation_method'=>(string)$mode,
            'prompt'=>'Local contract fixture; no task is submitted',
            'reference_assets'=>fixtureReferences((string)$mode,!empty($option['reference_audio_requires_visual'])),
        ];
        $durations=(array)($option['durations']??[]);
        $duration=(int)($option['default_duration']??0);
        if ($duration<=0) $duration=(int)($durations[0]??0);
        if ($duration>0) $params['duration']=$duration;
        $ratio=(string)($option['ratio_options'][0]??'');
        if ($ratio!=='' && strtolower($ratio)!=='auto') $params['ratio']=$ratio;
        $resolution=(string)($option['default_resolution']??'');
        if ($resolution!=='') $params['resolution']=$resolution;
        try {
            $quote=AigcVideoService::estimate(1,$params);
            if ((int)($quote['market_product_id']??0)!==(int)$option['market_product_id'] || (int)($quote['market_sku_id']??0)<=0) {
                throw new RuntimeException('quote resolved a different product or no SKU');
            }
            echo 'PASS ', $option['name'],' / ',$mode,' / SKU ',$quote['market_sku_id'],PHP_EOL;
        } catch (Throwable $error) {
            $errors[]=(string)$option['name'].' / '.$mode.': '.$error->getMessage();
            echo 'FAIL ',end($errors),PHP_EOL;
        }
        $checked++;
    }
}
agentCheck(count($options)>0,'live tenant video catalogue is available');
echo 'Checked ',count($options),' products and ',$checked,' advertised mode quotes without a Provider call or point reservation',PHP_EOL;
if ($errors!==[]) throw new RuntimeException(count($errors).' advertised video modes could not be quoted');
