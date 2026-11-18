<?php
require dirname(__DIR__).'/vendor/autoload.php';

use app\common\service\power\MarketReferenceVideoDuration as Limits;

function verify(bool $ok, string $why): void
{
    if (!$ok) throw new RuntimeException($why);
}

function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        verify(str_contains($error->getMessage(), $message), 'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

$cdn = 'https://fixtures.invalid/';
$probe = static fn(string $url) => (float)basename($url, '.mp4');
$seedance = Limits::limits(['upstream_metadata'=>['model_code'=>'bytedance-seedance-2-mini']], 'official-local-alias');
verify($seedance === ['single'=>15, 'total'=>15], 'Exact upstream identity must resolve Mini limits');
Limits::validate(['reference_video_urls'=>[$cdn.'8.mp4', $cdn.'7.mp4']], $seedance, [], $probe);
rejects(fn()=>Limits::validate(['reference_video_urls'=>[$cdn.'30.mp4']], $seedance, [], $probe), '单个参考视频不能超过15秒');
rejects(fn()=>Limits::validate(['reference_video_urls'=>[$cdn.'8.mp4', $cdn.'8.mp4']], $seedance, [], $probe), '参考视频总时长不能超过15秒');
rejects(fn()=>Limits::validate(['content'=>[['type'=>'video_url','video_url'=>['url'=>$cdn.'15.01.mp4'],'duration'=>2]]], $seedance, [], $probe), '单个参考视频不能超过15秒');
$wan = Limits::limits([], 'wan-2-7');
Limits::validate(['reference_video'=>[$cdn.'30.mp4']], $wan, [], $probe);
rejects(fn()=>Limits::validate(['input'=>['media'=>[['type'=>'first_clip','url'=>$cdn.'11.mp4']]]], $wan, [], $probe), '单个参考视频不能超过10秒');
$kling = Limits::limits([], 'kling-3-0-omni');
Limits::validate(['video_urls'=>[$cdn.'15.5.mp4']], $kling, [], $probe);
rejects(fn()=>Limits::validate(['video_urls'=>[$cdn.'15.51.mp4']], $kling, [], $probe), '单个参考视频不能超过15.5秒');
verify(Limits::limits(['reference_video_duration_limits'=>['single'=>12,'total'=>11]], 'bytedance-seedance-2-mini') === ['single'=>12.0,'total'=>11.0], 'Tighter catalog limits ignored');
Limits::validate(['duration'=>120, 'image_url'=>$cdn.'ignore.jpg'], $seedance, [], static fn()=>throw new RuntimeException('Non-video data must not be probed'));
verify(Limits::limits([], 'unverified-video-model') === [], 'Unknown model acquired a guessed maximum');

$fullVideo = Limits::limits([], '', 'full_video', 'submit');
$nativeFull = static fn(array $clips) => ['content'=>array_map(static fn($seconds)=>[
    'type'=>'video_url', 'role'=>'reference_video', 'video_url'=>['url'=>$cdn.$seconds.'.mp4'],
], $clips)];
Limits::validate($nativeFull([7, 8]), $fullVideo, [], $probe);
rejects(fn()=>Limits::validate($nativeFull([1.9]), $fullVideo, [], $probe), '单个参考视频至少需要2秒');
rejects(fn()=>Limits::validate($nativeFull([16]), $fullVideo, [], $probe), '单个参考视频不能超过15秒');
rejects(fn()=>Limits::validate($nativeFull([8, 8]), $fullVideo, [], $probe), '参考视频总时长不能超过15秒');
verify(Limits::limits(['reference_video_duration_limits'=>['minimum'=>1, 'single'=>18]], '', 'full_video', 'submit')['minimum'] === 2, 'Catalog must not lower the H3 minimum');

$klingClips = Limits::limits(['upstream_metadata'=>['model_code'=>'kling-3.0-video']]);
$visualProbe = static fn(string $url) => str_ends_with($url,'.png')
    ? ['kind'=>'image'] : ['kind'=>'video', 'duration'=>$probe($url)];
$element = static fn(string $file, array $window = []) => ['input'=>['kling_elements'=>[
    ['element_input_urls'=>[$cdn.$file]] + $window,
]]];
Limits::validate($element('12.mp4', ['start_time'=>3000, 'end_time'=>11000]), $klingClips, [], $probe, $visualProbe);
Limits::validate($element('3.mp4'), $klingClips, [], $probe, $visualProbe);
Limits::validate($element('12.mp4'), $klingClips, [], $probe, $visualProbe);
Limits::validate($element('ignore.png', ['end_time'=>9000]), $klingClips, [], $probe, $visualProbe);
rejects(fn()=>Limits::validate($element('12.mp4', ['end_time'=>9000]), $klingClips, [], $probe, $visualProbe), '当前片段为9秒');
rejects(fn()=>Limits::validate($element('6.mp4', ['end_time'=>8000]), $klingClips, [], $probe, $visualProbe), '结束点不超过原视频');
rejects(fn()=>Limits::validate($element('2.mp4'), $klingClips, [], $probe, $visualProbe), '当前片段为2秒');

$subjectMetadata = ['upstream_metadata'=>['model_code'=>'kling-3.0-video'],'material_capabilities'=>['modes'=>[[
    'method'=>'text_to_video','fields'=>[['field'=>'kling_elements','kind'=>'subject','role'=>'reference','maximum'=>3]],
]]]];
$subjectRequest = ['generation_method'=>'text_to_video','reference_assets'=>[
    ['type'=>'video','role'=>'reference_video','url'=>$cdn.'8.mp4','duration'=>8],
]];
$materialClass = app\common\service\power\MarketGenerationMaterials::class;
$subjectPayload = $materialClass::serialize($subjectMetadata, $subjectRequest);
verify($subjectPayload['kling_elements'][0]['element_input_urls'] === [$cdn.'8.mp4'], 'Uploaded video was not encoded as a named subject');
verify($subjectPayload['kling_elements'][0]['end_time'] === 8000, 'Whole source clip was not encoded in milliseconds');
$subjectPayload['prompt'] = '沿用@视频1 的动作';
$subjectPayload = $materialClass::compileReferencePrompt($subjectMetadata, $materialClass::select($subjectMetadata, $subjectRequest), $subjectRequest, $subjectPayload);
verify($subjectPayload['prompt'] === '沿用@video_subject_1 的动作', 'UI mention did not resolve to the provider subject name');
$materialClass::validate($subjectMetadata, $subjectRequest, $subjectPayload);
Limits::validate($subjectPayload, $klingClips, [], $probe, $visualProbe);

echo "PASS reference-video limits, cumulative boundaries, and named Kling subject compilation\n";
