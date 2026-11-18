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

echo "PASS model reference-video limits, measured duration, cumulative clips, fractional and role-specific boundaries\n";
