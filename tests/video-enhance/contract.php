<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\service\ai\GenerationSubmission;
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
echo "video enhancement contract PASS\n";
