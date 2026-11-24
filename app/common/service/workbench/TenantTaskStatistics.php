<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use app\common\model\user\User;
use app\common\service\recharge\CustomRechargeService;
use think\facade\Db;

final class TenantTaskStatistics
{
    public static function report(int $tenant, array $params): array
    {
        if ($tenant <= 0) throw new \InvalidArgumentException('租户身份无效');
        $range = OperationsRange::resolve($params + ['range' => 'today']);
        $appCode = trim((string)($params['app_code'] ?? ''));
        $userId = (string)($params['user_id'] ?? '');
        if ($userId !== '' && (!ctype_digit($userId) || (int)$userId <= 0)) {
            throw new \InvalidArgumentException('请输入有效的用户ID');
        }

        $window = static function (string $table, string $time) use ($tenant, $range, $appCode, $userId) {
            $query = Db::name($table)->where('tenant_id', $tenant)
                ->where($time, '>=', $range['start_at'])->where($time, '<', $range['end_at']);
            if ($appCode !== '') $query->where('app_code', $appCode);
            if ($userId !== '') $query->where('user_id', (int)$userId);
            return $query;
        };
        $ledger = $window('ai_consumption_log', 'finish_time')->where('billing_status', 'settled');
        $calls = $window('ai_consumption_log', 'create_time')->where('model_code', '<>', '');
        $tasks = $window('ai_app_task', 'create_time')->where('parent_task_id', 0);
        $rank = static function ($query, string $column) {
            return $query->field($column)->fieldRaw('COUNT(*) AS uses')->group($column)
                ->order('uses', 'desc')->order($column, 'asc')->limit(10)->select()->toArray();
        };
        $modelRank = $rank($calls, 'model_code');
        $appRank = $rank(clone $tasks, 'app_code');
        $userRank = $rank((clone $tasks)->where('user_id', '>', 0), 'user_id');
        $apps = Db::name('app')->whereIn('status', ['installed', 'disabled'])->field('code,name')->order('name')->select()->toArray();
        $names = array_column($apps, 'name', 'code');
        foreach ($appRank as &$row) $row['name'] = $names[$row['app_code']] ?? $row['app_code'];
        unset($row);
        $users = $userRank ? User::where('tenant_id', $tenant)->whereIn('id', array_column($userRank, 'user_id'))
            ->column('nickname', 'id') : [];
        foreach ($userRank as &$row) $row['name'] = ($users[$row['user_id']] ?? '') ?: '用户';
        unset($row);

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
        $statuses = $tasks->field('status')->fieldRaw('COUNT(*) AS uses')->group('status')->order('uses', 'desc')->select()->toArray();
        return [
            'range' => $range, 'apps' => $apps,
            'balance' => Db::name('tenant')->where('id', $tenant)->value('point_balance'),
            'user_points' => $userPoints, 'cost_points' => $costPoints,
            'user_amount' => $revenue, 'cost_amount' => $cost,
            'gross_profit' => round($revenue - $cost, 2),
            'points_per_yuan' => $retailRatio,
            'trend' => ['user_points' => $incomeTrend, 'cost_points' => $costTrend],
            'statuses' => $statuses, 'models' => $modelRank, 'tools' => $appRank, 'users' => $userRank,
        ];
    }
}
