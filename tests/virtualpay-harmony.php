<?php
declare(strict_types=1);
require __DIR__.'/../app/common/service/virtualpay/VirtualPayPolicy.php';
require __DIR__.'/../app/common/service/virtualpay/VirtualPayConfigService.php';
use app\common\service\virtualpay\VirtualPayPolicy as Policy;
use app\common\service\virtualpay\VirtualPayConfigService as Config;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
check(!Config::enabledFor(['enabled'=>1,'enabled_platforms'=>'ios'], 'ohos'), 'iOS-only configuration must not enable HarmonyOS');
check(Config::enabledFor(['enabled'=>1,'enabled_platforms'=>'android'], 'ohos'), 'Android configuration covers the non-Apple HarmonyOS channel');
check(!Config::enabledFor(['enabled'=>0,'enabled_platforms'=>'android'], 'ohos'), 'disabled configuration stays disabled');
check(Config::enabledFor(['enabled'=>1,'enabled_platforms'=>'ios'], ''), 'legacy missing platform retains its restriction');
check(Policy::paymentPlatform('ohos') === 'android', 'prepay configuration uses the same platform mapping');
Policy::client('ohos', 0, 50);
Policy::client('android', 0, 50);
Policy::client('ios', 0, 100);
foreach ([['ios',0,50], ['ios',1,100], ['windows',0,100], ['ohos',0,0]] as $input) {
    $rejected = false;
    try { Policy::client(...$input); } catch (InvalidArgumentException|RuntimeException $e) { $rejected = true; }
    check($rejected, 'Existing amount, Apple environment, and unsupported platform checks remain enforced');
}
echo "PASS HarmonyOS platform selection, prepay policy and existing restrictions\n";
