<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use think\facade\Db;

final class PlatformTaskStatistics
{
    private const POINTS_PER_YUAN = 100;

    public static function report(array $params): array
    {
        $range = OperationsRange::resolve($params + ['range' => 'today']);
        $tenants = Db::name('tenant')->whereNull('delete_time')->field('id,name')->order('id')->select()->toArray();
        $names = array_column($tenants, 'name', 'id');
        $id = $params['tenant_id'] ?? '';
        $tenant = null;
        if ($id !== '') {
            $tenant = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$tenant || !isset($names[$tenant])) throw new \InvalidArgumentException('请选择有效租户');
        }
        $window = TaskStatisticsActivity::window($tenant, $range, $params);
        $income = $cost = $missing = array_fill(0, count($range['labels']), 0);
        $userPoints = 0.;
        $ledger = $window('ai_consumption_log', 'finish_time')->where('billing_status', 'settled');
        $ledger->field('id,finish_time,actual_tenant_cost,actual_user_price,quantity,usage_snapshot,price_snapshot')
            ->chunk(250, static function ($rows) use (&$income, &$cost, &$missing, &$userPoints, $range) {
                foreach ($rows as $row) {
                    $slot = intdiv((int)$row['finish_time'] - $range['start_at'], $range['step']);
                    $userPoints += (float)$row['actual_user_price'];
                    $income[$slot] += (float)$row['actual_tenant_cost'];
                    $supplier = PlatformTaskCost::points($row);
                    if ($supplier === null) $missing[$slot]++;
                    else $cost[$slot] += $supplier;
                }
            }, 'id');
        $supply = round(array_sum($income), 6);
        $knownCost = round(array_sum($cost), 6);
        $unpriced = array_sum($missing);
        $supplyAmount = round($supply/self::POINTS_PER_YUAN, 2);
        $costAmount = $unpriced ? null : round($knownCost/self::POINTS_PER_YUAN, 2);
        $balances = Db::name('tenant')->whereNull('delete_time');
        if ($tenant !== null) $balances->where('id', $tenant);
        return [
            'range'=>$range, 'tenants'=>$tenants, 'balance'=>$balances->sum('point_balance'),
            'user_points'=>round($userPoints, 6), 'supply_points'=>$supply, 'supply_amount'=>$supplyAmount, 'cost_points'=>$unpriced ? null : $knownCost,
            'known_cost_points'=>$knownCost, 'unpriced_count'=>$unpriced, 'cost_amount'=>$costAmount,
            'gross_profit'=>$costAmount === null ? null : round($supplyAmount-$costAmount, 2),
            'points_per_yuan'=>self::POINTS_PER_YUAN, 'cost_points_per_yuan'=>self::POINTS_PER_YUAN,
            'trend'=>['supply_points'=>array_map(static fn($v)=>round($v, 6), $income),
                'cost_points'=>array_map(static fn($v, $n)=>$n ? null : round($v, 6), $cost, $missing)],
        ] + TaskStatisticsActivity::report($window, $tenant, $names);
    }
}
