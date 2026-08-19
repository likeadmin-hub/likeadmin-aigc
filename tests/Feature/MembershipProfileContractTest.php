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
        $sidebar = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/ai-sidebar.50b22e69.js');

        $this->assertStringContainsString("'is_member'", $service);
        foreach (['member_status_text', 'membership_plan', 'member_cycle_text', 'member_expire_time_text'] as $field) {
            $this->assertStringContainsString("'{$field}'", $service);
            $this->assertStringContainsString($field, $sidebar);
        }
        $this->assertStringContainsString('ai-user-membership', $sidebar);
    }
}
