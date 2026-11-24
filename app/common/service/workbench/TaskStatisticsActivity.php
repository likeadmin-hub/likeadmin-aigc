<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use app\common\model\user\User;
use think\facade\Db;

final class TaskStatisticsActivity
{
    public static function window(?int $tenant, array $range, array $params): callable
    {
        $app = trim((string)($params['app_code'] ?? ''));
        $user = $params['user_id'] ?? '';
        if ($user !== '' && !filter_var($user, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
            throw new \InvalidArgumentException('请输入有效的用户ID');
        }
        return static function (string $table, string $time) use ($tenant, $range, $app, $user) {
            $query = Db::name($table)->where($time, '>=', $range['start_at'])->where($time, '<', $range['end_at']);
            if ($tenant !== null) $query->where('tenant_id', $tenant);
            if ($app !== '') $query->where('app_code', $app);
            if ($user !== '') $query->where('user_id', (int)$user);
            return $query;
        };
    }

    public static function report(callable $window, ?int $tenant, ?array $tenantNames = null): array
    {
        $calls = $window('ai_consumption_log', 'create_time')->where('model_code', '<>', '');
        $tasks = $window('ai_app_task', 'create_time')->where('parent_task_id', 0);
        $rank = static function ($query, string $columns) {
            return $query->field($columns)->fieldRaw('COUNT(*) AS uses')->group($columns)
                ->order('uses', 'desc')->order($columns)->limit(10)->select()->toArray();
        };
        $models = $rank($calls, 'model_code');
        $tools = $rank(clone $tasks, 'app_code');
        $users = $rank((clone $tasks)->where('user_id', '>', 0), $tenantNames === null ? 'user_id' : 'tenant_id,user_id');
        $apps = Db::name('app')->whereIn('status', ['installed', 'disabled'])->field('code,name')->order('name')->select()->toArray();
        $names = array_column($apps, 'name', 'code');
        foreach ($tools as &$row) $row['name'] = $names[$row['app_code']] ?? $row['app_code'];
        unset($row);
        $nicknames = $tenantNames === null && $users ? User::where('tenant_id', $tenant)
            ->whereIn('id', array_column($users, 'user_id'))->column('nickname', 'id') : [];
        foreach ($users as &$row) {
            $row['name'] = $tenantNames === null ? (($nicknames[$row['user_id']] ?? '') ?: '用户')
                : ($tenantNames[$row['tenant_id']] ?? '租户 #'.$row['tenant_id']).' · 用户';
        }
        unset($row);
        $statuses = $tasks->field('status')->fieldRaw('COUNT(*) AS uses')->group('status')->order('uses', 'desc')->select()->toArray();
        return compact('apps', 'models', 'tools', 'users', 'statuses');
    }
}
