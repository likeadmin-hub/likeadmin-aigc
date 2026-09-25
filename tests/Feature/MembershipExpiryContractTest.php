<?php

namespace Tests\Feature;

use app\common\service\membership\MembershipService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MembershipExpiryContractTest extends TestCase
{
    public function testPurchasingFromPermanentFreeMembershipStartsPaidTermNow(): void
    {
        $method = new ReflectionMethod(MembershipService::class, 'calcExpire');
        $method->setAccessible(true);

        $expires = $method->invoke(null, 4294967295, 1);
        self::assertGreaterThan(time() + 25 * 86400, $expires);
        self::assertLessThan(time() + 32 * 86400, $expires);
        self::assertLessThanOrEqual(4294967295, $expires);
    }

    public function testPurchasingFromPaidMembershipExtendsExistingTerm(): void
    {
        $method = new ReflectionMethod(MembershipService::class, 'calcExpire');
        $method->setAccessible(true);

        $currentExpiry = strtotime('+2 months');
        self::assertSame(strtotime('+1 month', $currentExpiry), $method->invoke(null, $currentExpiry, 1));
    }
}
