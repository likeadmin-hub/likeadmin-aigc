<?php
declare(strict_types=1);

namespace app\common\service\app;

use think\facade\Db;
use think\facade\Log;
use Throwable;

final class AppCatalogUsage
{
    /** Count users who submitted a top-level application task in the last 30 days. */
    public static function withCounts(array $apps, ?int $tenantId = null): array
    {
        $codes = array_unique(array_filter(array_column($apps, 'code')));
        if (!$codes) return $apps;

        $now = time();
        try {
            $query = Db::name('ai_app_task')
                ->whereIn('app_code', $codes)
                ->where('parent_task_id', 0)
                ->where('tenant_id', '>', 0)
                ->where('user_id', '>', 0)
                ->whereBetween('create_time', [$now - 30 * 86400, $now]);
            if ($tenantId !== null) $query->where('tenant_id', $tenantId);
            $counts = $query->field('app_code')
                ->fieldRaw("COUNT(DISTINCT tenant_id, user_id) AS user_count")
                ->group('app_code')->select()->column('user_count', 'app_code');
        } catch (Throwable $error) {
            Log::warning('Application catalog usage unavailable: ' . $error->getMessage());
            $counts = null;
        }

        foreach ($apps as &$app) {
            $app['usage_user_count'] = $counts === null ? null : (int)($counts[$app['code']] ?? 0);
        }
        unset($app);
        return $apps;
    }
}
