<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use app\common\service\recharge\CustomRechargeService;
use think\facade\Db;

final class TenantTaskStatistics
{
    public static function report(int $tenant, array $params): array
    {
        if ($tenant <= 0) throw new \InvalidArgumentException('租户身份无效');
        $range = OperationsRange::resolve($params + ['range' => 'today']);
        $window = TaskStatisticsActivity::window($tenant, $range, $params);
        $ledger = $window('ai_consumption_log', 'finish_time')->where('billing_status', 'settled');
        $step = $range['step'];
        $start = $range['start_at'];
        $bucket = "FLOOR((finish_time - {$start}) / {$step})";
        $buckets = $ledger->fieldRaw("{$bucket} AS slot, SUM(actual_user_price) user_points, SUM(actual_tenant_cost) cost_points")
            ->group('slot')->select()->toArray();
        $incomeTrend = $costTrend = array_fill(0, count($range['labels']), 0);
        foreach ($buckets as $row) {
            $incomeTrend[(int)$row['slot']] = (float)$row['user_points'];
            $costTrend[(int)$row['slot']] = (float)$row['cost_points'];
        }
        $userPoints = round(array_sum($incomeTrend), 6);
        $costPoints = round(array_sum($costTrend), 6);
        $retailRatio = (float)CustomRechargeService::ratio();
        $revenue = round($userPoints / $retailRatio, 2);
        $cost = round($costPoints / $retailRatio, 2);
        return [
            'range' => $range,
            'balance' => Db::name('tenant')->where('id', $tenant)->value('point_balance'),
            'user_points' => $userPoints, 'cost_points' => $costPoints,
            'user_amount' => $revenue, 'cost_amount' => $cost,
            'gross_profit' => round($revenue - $cost, 2),
            'points_per_yuan' => $retailRatio,
            'trend' => ['user_points' => $incomeTrend, 'cost_points' => $costTrend],
        ] + TaskStatisticsActivity::report($window, $tenant);
    }
}
