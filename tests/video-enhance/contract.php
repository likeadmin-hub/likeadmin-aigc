<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\service\ai\GenerationSubmission;
use app\common\service\app\aigc_video_enhance\VideoEnhanceConfig;
use app\common\service\app\aigc_video_enhance\VideoEnhanceService;
use app\common\service\open_platform\OpenException;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\power\MarketGenerationAdapterRegistry;
use app\common\service\power\MarketGenericImageAppRuntimeService as Runtime;

function check(bool $actual, string $message): void
{
    if (!$actual) throw new RuntimeException($message);
}
function fixture(string $method, ...$arguments): mixed
{
    return (new ReflectionMethod(Runtime::class, $method))->invoke(null, ...$arguments);
}

$flash = ['app_code'=>'flashvsr'];
$market = ['product'=>['upstream_app_code'=>'flashvsr'], 'sku'=>['usage_unit'=>'input_second', 'usage_unit_size'=>1]];
check(MarketGenerationAdapterRegistry::application(['upstream_app_code'=>'flashvsr']) === Runtime::class, 'FlashVSR must use the app API adapter');
check(GenerationSubmission::operation('app.aigc_video_enhance.Generate','index') === 'app.aigc_video_enhance.generate/index', 'Paid creation requires a durable submission receipt');
check(fixture('quantity', $market, 5.055) === 5.055, 'Decimal input seconds must survive quoting');
check(fixture('charge', $market, 4.0, 5.055) === 20.22, 'Use input seconds and the selected rate');
try {
    fixture('quantity', $market, 30.01);
    throw new RuntimeException('Videos longer than 30 seconds were accepted');
} catch (InvalidArgumentException $expected) {}

$input = ['url'=>'https://example.test/demo.mp4','bit_rate'=>10,'duration'=>5.055];
check(fixture('payload', $flash, $input) === ['input_url'=>$input['url'],'bit_rate'=>10,'duration'=>5.055], 'FlashVSR submission sends the expected video and bit rate only');
check(fixture('payload', ['app_code'=>'watermark_removal'], $input) === ['url'=>$input['url']], 'Existing watermark request remains intact');
check(fixture('status', ['code'=>1,'data'=>['result'=>['status'=>'completed']]], false) === 'success', 'Completed query wrapper must be recognised');
check(fixture('status', ['code'=>0,'msg'=>'temporary read error'], false) === 'running', 'An error response alone cannot refund a task');
check(fixture('status', ['code'=>1,'data'=>['status'=>'failed']], false) === 'failed', 'Explicit terminal failure remains refundable');

check(VideoEnhanceConfig::validate(['max_seconds'=>12, 'allow_link'=>0], true, 25) === ['max_seconds'=>12, 'allow_link'=>0], 'Missing tenant overrides must inherit');
try {
    VideoEnhanceConfig::validate(['max_seconds'=>26], true, 25);
    throw new RuntimeException('Tenant duration exceeded platform limit');
} catch (InvalidArgumentException $expected) {}
check(VideoEnhanceConfig::selected(['sku_id'=>30], [['market_sku_id'=>31]]) === null, 'Unavailable explicit SKU must not fall back to a different price');
check(VideoEnhanceConfig::selected(['sku_id'=>0], [['market_sku_id'=>31]])['market_sku_id'] === 31, 'Existing installations use the first available SKU by default');

$delivery = new ReflectionMethod(VideoEnhanceService::class, 'deliveryBlock');
check($delivery->invoke(null, ['storage_engine'=>'qcloud']) === '', 'Owned COS media must not require a DNS probe to calculate its rate');
check($delivery->invoke(null, ['storage_engine'=>'local', 'source_url'=>'http://127.0.0.1/uploads/fixture.mp4']) !== '', 'An estimate must flag local media the upstream cannot read');
try {
    OpenSafeHttp::resolve('http://127.0.0.1/uploads/fixture.mp4', false);
    throw new RuntimeException('An external input link must not access an internal address');
} catch (OpenException $expected) {}
echo "video enhancement contract PASS\n";
