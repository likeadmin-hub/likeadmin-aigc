<?php

namespace Tests\Feature;

use app\common\service\pay\WeChatPayService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class WechatPaymentAppIdContractTest extends TestCase
{
    public function testPlatformMerchantStillUsesTenantChannelAppId(): void
    {
        $service = (new ReflectionClass(WeChatPayService::class))->newInstanceWithoutConstructor();
        $platformPay = new ReflectionProperty(WeChatPayService::class, 'usePlatformPay');
        $platformPay->setAccessible(true);
        $platformPay->setValue($service, true);
        $config = new ReflectionProperty(WeChatPayService::class, 'config');
        $config->setAccessible(true);
        $config->setValue($service, ['app_id' => 'platform-app-id', 'mch_id' => 'platform-mch-id']);

        $method = new ReflectionMethod(WeChatPayService::class, 'getPaymentAppId');
        $method->setAccessible(true);
        self::assertSame('tenant-app-id', $method->invoke($service, ['app_id' => ' tenant-app-id ']));
    }

    public function testMissingTenantChannelAppIdFailsBeforeWechatRequest(): void
    {
        $service = (new ReflectionClass(WeChatPayService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WeChatPayService::class, 'getPaymentAppId');
        $method->setAccessible(true);

        $this->expectExceptionMessage('当前租户微信渠道未配置 AppID');
        $method->invoke($service, ['app_id' => '']);
    }
}
