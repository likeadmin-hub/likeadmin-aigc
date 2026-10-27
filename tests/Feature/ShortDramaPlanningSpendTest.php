<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningSpend;
use PHPUnit\Framework\TestCase;

class ShortDramaPlanningSpendTest extends TestCase
{
    public function testPaidPartialWorkIsVisibleWhenParentTaskStillShowsZero(): void
    {
        $rows = [];
        foreach ([87.40, 486.85] as $points) {
            $rows[] = ['result_json' => json_encode(['result' => ['billing' => [
                'billing_status' => 'deducted', 'user_charge_points' => $points]]])];
        }
        $rows[] = ['result_json' => json_encode(['result' => ['billing' => [
            'billing_status' => 'settlement_failed', 'user_charge_points' => 100]]])];
        self::assertSame(['settled_points' => '574.25', 'provider_units' => 3, 'pending_units' => 1],
            ShortDramaPlanningSpend::fromRows($rows, 0));
        self::assertSame('9.00', ShortDramaPlanningSpend::fromRows([], 9)['settled_points']);
    }
}
