<?php

namespace Tests\Feature;

use app\tenantapi\logic\WorkbenchLogic;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class WorkbenchRechargeContractTest extends TestCase
{
    public function testSummaryCardDisplaysRechargeAmountsInYuan(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/logic/WorkbenchLogic.php');
        $formatCurrency = new ReflectionMethod(WorkbenchLogic::class, 'formatCurrency');
        $formatCurrency->setAccessible(true);

        self::assertIsString($source);
        self::assertStringContainsString("'title' => '今日充值'", $source);
        self::assertStringContainsString("'value' => \$today['today_recharge_amount']", $source);
        self::assertStringContainsString("'unit' => '元'", $source);
        self::assertStringContainsString("'sub_title' => '累计充值'", $source);
        self::assertStringContainsString("self::formatCurrency(\$today['recharge_amount_total'])", $source);
        self::assertSame('56.78 元', $formatCurrency->invoke(null, 56.78));
    }

    public function testRechargeQueryUsesPaidUnrefundedOrdersAndPaymentTime(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/logic/WorkbenchLogic.php');

        self::assertIsString($source);
        self::assertStringContainsString("->where('pay_status', PayEnum::ISPAID)", $source);
        self::assertStringContainsString("->where('refund_status', 0)", $source);
        self::assertStringContainsString("->whereNull('delete_time')", $source);
        self::assertStringContainsString("'pay_time'", $source);
        self::assertStringContainsString("->sum('order_amount')", $source);
    }
}
