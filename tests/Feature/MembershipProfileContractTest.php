<?php

use app\common\service\membership\MembershipService;
use PHPUnit\Framework\TestCase;

class MembershipProfileContractTest extends TestCase
{
    /**
     * @dataProvider cycleProvider
     */
    public function testMembershipCycleLabels(string $cycle, int $durationMonths, array $expected): void
    {
        $method = new ReflectionMethod(MembershipService::class, 'formatMembershipCycle');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $cycle, $durationMonths));
    }

    public function cycleProvider(): array
    {
        return [
            'daily' => ['daily', 0, ['cycle' => 'daily', 'text' => '日套餐']],
            'monthly' => ['monthly', 1, ['cycle' => 'monthly', 'text' => '月套餐']],
            'yearly' => ['yearly', 12, ['cycle' => 'yearly', 'text' => '年套餐']],
            'fixed one month' => ['package', 1, ['cycle' => 'monthly', 'text' => '月套餐']],
            'fixed two years' => ['package', 24, ['cycle' => 'yearly', 'text' => '2年套餐']],
            'fixed three months' => ['package', 3, ['cycle' => 'package', 'text' => '3个月套餐']],
        ];
    }

    public function testProfileResponseAndUiExposeMembershipFields(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/membership/MembershipService.php');
        $userLogic = file_get_contents(dirname(__DIR__, 2) . '/app/api/logic/UserLogic.php');
        $userController = file_get_contents(dirname(__DIR__, 2) . '/app/api/controller/UserController.php');
        $sidebar = \Tests\Support\CompiledAssets::containing('pc', 'ai-user-membership');

        $this->assertStringContainsString("'is_member'", $service);
        foreach (['member_status_text', 'membership_plan', 'member_cycle_text', 'member_expire_time_text'] as $field) {
            $this->assertStringContainsString("'{$field}'", $service);
            $this->assertStringContainsString($field, $sidebar);
        }
        $this->assertStringContainsString("MembershipService::status((int)(\$userInfo['tenant_id'] ?? 0), (int)\$userInfo['user_id'])", $userLogic);
        $this->assertStringContainsString('UserLogic::center($this->userInfo)', $userController);
        $this->assertStringContainsString("UserLogic::info(\$this->userId, (int)(\$this->userInfo['tenant_id'] ?? 0))", $userController);
        $this->assertStringContainsString('getUserCenter()', \Tests\Support\CompiledAssets::source('pc/stores/user.ts'));
        $this->assertStringContainsString('ai-user-membership', $sidebar);
    }

    public function testMembershipPaymentStatusReconcilesDelayedCallbacks(): void
    {
        $paymentLogic = file_get_contents(dirname(__DIR__, 2) . '/app/common/logic/PaymentLogic.php');
        $reconcile = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/pay/MembershipPaymentReconcileService.php');

        $this->assertStringContainsString('MembershipPaymentReconcileService::reconcile($order)', $paymentLogic);
        $this->assertStringContainsString("PayNotifyLogic::handle('membership'", $reconcile);
        $this->assertStringContainsString('checkPay($paySn)', $reconcile);
        $this->assertStringContainsString('checkPay((string)$order[\'order_sn\'])', $reconcile);
        $this->assertStringContainsString("'transaction_id' => (string)\$payment['transaction_id']", $reconcile);
    }
}
