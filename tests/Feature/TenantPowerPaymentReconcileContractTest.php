<?php

namespace Tests\Feature;

use app\common\service\pay\TenantPowerPaymentReconcileService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class TenantPowerPaymentReconcileContractTest extends TestCase
{
    public function testPlatformWechatPaymentsUseDedicatedCallbackEndpoints(): void
    {
        $service = $this->read('app/common/service/pay/WeChatPayService.php');
        $controller = $this->read('app/api/controller/PayController.php');

        self::assertStringContainsString("url('api/pay/notifyPlatformOa'", $service);
        self::assertStringContainsString("url('api/pay/notifyPlatformMnp'", $service);
        self::assertStringContainsString("url('api/pay/notifyPlatformApp'", $service);
        self::assertStringContainsString('notifyPlatformOa', $controller);
        self::assertStringContainsString('UserTerminalEnum::WECHAT_OA, null, true', $controller);
    }

    public function testPaymentStatusPollReconcilesBeforeReturningOrderState(): void
    {
        $source = $this->read('app/common/logic/PaymentLogic.php');

        self::assertStringContainsString('TenantPowerPaymentReconcileService::reconcile($order)', $source);
        self::assertStringContainsString("'pay_status' => \$order['pay_status']", $source);
        self::assertStringContainsString("'pay_time' => \$payTime", $source);
    }

    public function testReconciliationUsesTransactionalIdempotentSettlement(): void
    {
        $reconcile = $this->read('app/common/service/pay/TenantPowerPaymentReconcileService.php');
        $notify = $this->read('app/common/logic/PayNotifyLogic.php');
        $mall = $this->read('app/common/service/power/TenantPowerMallService.php');

        self::assertStringContainsString('PayNotifyLogic::handle(TenantPowerMallService::FROM', $reconcile);
        self::assertStringContainsString('Db::startTrans()', $notify);
        self::assertStringContainsString("if ((int)\$order['pay_status'] === PayEnum::ISPAID)", $mall);
        self::assertStringContainsString("'source_order_sn' => \$orderSn", $mall);
    }

    public function testAmountValidationRejectsMismatchedProviderAmount(): void
    {
        $method = new ReflectionMethod(TenantPowerPaymentReconcileService::class, 'assertSameAmount');
        $method->setAccessible(true);
        $method->invoke(null, 199.00, 199.00);
        $this->expectException(RuntimeException::class);
        $method->invoke(null, 199.00, 198.99);
    }

    public function testHistoricalRepairCommandIsRegistered(): void
    {
        $console = $this->read('config/console.php');
        $command = $this->read('app/common/command/ReconcileTenantPowerOrders.php');

        self::assertStringContainsString("'tenant_power:reconcile'", $console);
        self::assertStringContainsString("->addOption('apply'", $command);
        self::assertStringContainsString('TenantPowerPaymentReconcileService::inspect($order)', $command);
    }

    private function read(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
