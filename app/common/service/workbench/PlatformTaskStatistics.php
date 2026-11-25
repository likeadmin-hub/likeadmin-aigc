<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use think\facade\Db;

final class PlatformTaskStatistics
{
    private const POINTS_PER_YUAN = 100;
    private const REVIEW_PAGE_SIZE = 20;

    public static function report(array $params, bool $canReview = false): array
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
        $reviewPage = max(1, (int)($params['cost_review_page'] ?? 1));
        $reviewStart = min($reviewPage - 1, intdiv(PHP_INT_MAX, self::REVIEW_PAGE_SIZE)) * self::REVIEW_PAGE_SIZE;
        $reviewRows = [];
        $unpriced = 0;
        $userPoints = 0.;
        $ledger = $window('ai_consumption_log', 'finish_time')->where('billing_status', 'settled');
        $fields = 'id,finish_time,actual_tenant_cost,actual_user_price,quantity,usage_snapshot,price_snapshot';
        if ($canReview) $fields .= ',consume_no,tenant_id,app_code,model_code,api_code,upstream_request_id,upstream_task_id';
        $ledger->field($fields)
            ->chunk(250, static function ($rows) use (&$income, &$cost, &$missing, &$userPoints, &$unpriced, &$reviewRows, $range, $canReview, $reviewStart, $names) {
                $unknown = [];
                foreach ($rows as $row) {
                    $slot = intdiv((int)$row['finish_time'] - $range['start_at'], $range['step']);
                    $userPoints += (float)$row['actual_user_price'];
                    $income[$slot] += (float)$row['actual_tenant_cost'];
                    $supplier = PlatformTaskCost::points($row);
                    if ($supplier === null) $unknown[(int)$row['id']] = $row;
                    else $cost[$slot] += $supplier;
                }
                $confirmed = PlatformSupplierCostReview::confirmedPoints(array_keys($unknown));
                foreach ($unknown as $id => $row) {
                    $slot = intdiv((int)$row['finish_time'] - $range['start_at'], $range['step']);
                    if (isset($confirmed[$id])) {
                        $cost[$slot] += $confirmed[$id];
                        continue;
                    }
                    $missing[$slot]++;
                    if ($canReview && $unpriced >= $reviewStart && count($reviewRows) < self::REVIEW_PAGE_SIZE) {
                        $reviewRows[] = self::reviewRow($row, $names);
                    }
                    $unpriced++;
                }
            }, 'id');
        $supply = round(array_sum($income), 6);
        $knownCost = round(array_sum($cost), 6);
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
        ] + ($canReview ? ['cost_review'=>['page_size'=>self::REVIEW_PAGE_SIZE, 'rows'=>$reviewRows]] : [])
            + TaskStatisticsActivity::report($window, $tenant, $names);
    }

    private static function reviewRow(array $row, array $names): array
    {
        $price = json_decode((string)$row['price_snapshot'], true);
        $usage = json_decode((string)$row['usage_snapshot'], true);
        $text = isset($price['input'], $price['output']);
        return [
            'id' => (int)$row['id'],
            'consume_no' => $row['consume_no'],
            'tenant' => $names[$row['tenant_id']] ?? ('#'.$row['tenant_id']),
            'app_code' => $row['app_code'],
            'model' => $row['model_code'] ?: $row['api_code'],
            'finish_time' => (int)$row['finish_time'],
            'upstream_request_id' => $row['upstream_request_id'],
            'upstream_task_id' => $row['upstream_task_id'],
            'usage' => $text ? array_intersect_key((array)$usage, array_flip(['prompt_tokens','cached_input_tokens','completion_tokens'])) : [],
            'reason' => $text && isset($usage['prompt_tokens'], $usage['completion_tokens'])
                ? '历史上游费率缺失或不完整' : '缺少可核算的上游费率或实际用量',
        ];
    }
}
