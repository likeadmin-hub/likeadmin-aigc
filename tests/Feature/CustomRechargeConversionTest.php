<?php
namespace Tests\Feature;

use app\common\service\recharge\CustomRechargeService;
use PHPUnit\Framework\TestCase;

class CustomRechargeConversionTest extends TestCase
{
    public function testCustomPointsAreConvertedToYuanWithoutRoundingDown(): void
    {
        self::assertSame(['points' => '800.00', 'amount' => '10.00'], CustomRechargeService::quote('800', '80'));
        self::assertSame(['points' => '10.00', 'amount' => '0.13'], CustomRechargeService::quote('10', '80'));
        self::assertSame(['points' => '0.01', 'amount' => '0.01'], CustomRechargeService::quote('0.01', '1000000'));
        self::assertSame(['points' => '10.50', 'amount' => '10.50'], CustomRechargeService::quote('10.50', '1'));
        self::assertSame(['points' => '99999999.99', 'amount' => '99999999.99'], CustomRechargeService::quote('99999999.99', '1'));
    }

    /** @dataProvider invalidInputs */
    public function testInvalidValuesCannotCreateAnUnderpricedOrder(string $points, string $ratio): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomRechargeService::quote($points, $ratio);
    }

    public static function invalidInputs(): array
    {
        return [['0','80'], ['-1','80'], ['1e3','80'], ['abc','80'], ['0.001','80'], ['100000000','80'], ['10','0'], ['10','-1'], ['10','abc'], ['10','1.001'], ['10','1000001'], ['99999999','0.01']];
    }
}
