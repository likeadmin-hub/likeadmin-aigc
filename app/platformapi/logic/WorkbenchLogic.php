<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\platformapi\logic;

use app\common\enum\PayEnum;
use app\common\logic\BaseLogic;
use app\common\model\tenant\Tenant;
use app\common\model\user\User;
use app\common\service\ConfigService;
use app\common\service\FileService;
use app\common\service\update\UpdateSourceClient;
use think\facade\Db;

/**
 * 工作台
 * Class WorkbenchLogic
 * @package app\platformapi\logic
 */
class WorkbenchLogic extends BaseLogic
{
    private const DAYS = 15;

    private const APP_DEFINITIONS = [
        [
            'code' => 'aigc_image',
            'name' => 'AIGC生图',
            'task_table' => 'aigc_image_task',
            'billing_table' => 'aigc_image_billing',
        ],
        [
            'code' => 'aigc_video',
            'name' => 'AIGC视频',
            'task_table' => 'aigc_video_task',
            'billing_table' => 'aigc_video_billing',
        ],
        [
            'code' => 'aigc_digital_human',
            'name' => '数字人视频',
            'task_table' => 'aigc_digital_human_task',
            'billing_table' => 'aigc_digital_human_billing',
        ],
        [
            'code' => 'image_human',
            'name' => '全驱数字人',
            'task_table' => 'image_human_task',
            'billing_table' => 'image_human_billing',
        ],
        [
            'code' => 'aigc_llm',
            'name' => 'AIGC对话',
            'usage_table' => 'aigc_llm_usage',
        ],
        [
            'code' => 'aigc_canvas',
            'name' => '无限画布',
            'task_table' => 'aigc_canvas_run',
        ],
    ];

    /**
     * @notes 工作台
     * @return array
     * @author 段誉
     * @date 2021/12/29 15:58
     */
    public static function index(): array
    {
        $appStats = self::appStats();
        $trend = self::trend();
        $today = self::today($appStats);

        return [
            'version' => self::versionInfo(),
            'today' => $today,
            'summary_cards' => self::summaryCards($today),
            'menu' => self::menu(),
            'visitor' => self::visitor($trend),
            'sale' => self::sale($trend),
            'trend' => $trend,
            'app_stats' => $appStats,
            'ranking' => self::ranking($appStats),
            'support' => self::support(),
        ];
    }

    /**
     * @notes 常用功能
     * @return array[]
     * @author 段誉
     * @date 2021/12/29 16:40
     */
    public static function menu(): array
    {
        return [
            [
                'name' => '接口渠道',
                'image' => FileService::getFileUrl(config('project.default_image.menu_generator')),
                'url' => '/update/channel',
            ],
            [
                'name' => '应用中心',
                'image' => FileService::getFileUrl(config('project.default_image.menu_auth')),
                'url' => '/apps/center',
            ],
            [
                'name' => '租户管理',
                'image' => FileService::getFileUrl(config('project.default_image.menu_admin')),
                'url' => '/tenant/lists',
            ],
            [
                'name' => 'AIGC生图',
                'image' => FileService::getFileUrl(config('project.default_image.menu_file')),
                'url' => '/aigc-image/spec',
            ],
            [
                'name' => 'AIGC视频',
                'image' => FileService::getFileUrl(config('project.default_image.menu_file')),
                'url' => '/aigc-video/spec',
            ],
            [
                'name' => '数字人',
                'image' => FileService::getFileUrl(config('project.default_image.menu_file')),
                'url' => '/aigc-digital-human/channel',
            ],
            [
                'name' => 'AIGC对话',
                'image' => FileService::getFileUrl(config('project.default_image.menu_dict')),
                'url' => '/aigc-llm/model',
            ],
            [
                'name' => '无限画布',
                'image' => FileService::getFileUrl(config('project.default_image.menu_web')),
                'url' => '/aigc-canvas/tenant-usage',
            ],
        ];
    }

    /**
     * @notes 版本信息
     * @return array
     * @author 段誉
     * @date 2021/12/29 16:08
     */
    public static function versionInfo(): array
    {
        $source = UpdateSourceClient::getSource();
        $url = self::baseUrlOrigin((string)($source['active_base_url'] ?? ''));

        return [
            'version' => config('project.version'),
            'website' => config('project.website.url'),
            'name' => ConfigService::get('platform', 'name'),
            'based' => 'vue3.x、ElementUI、MySQL',
            'channel' => [
                'interface' => [
                    'label' => '接口渠道',
                    'url' => $url,
                    'configured' => $url !== '',
                    'route' => '/update/channel',
                ],
            ],
        ];
    }

    /**
     * @notes 今日数据
     * @param array $appStats
     * @return array
     * @author 段誉
     * @date 2021/12/29 16:15
     */
    public static function today(array $appStats = []): array
    {
        $todayStart = strtotime('today');
        $taskTotal = self::sumColumn($appStats, 'task_total');
        $successTotal = self::sumColumn($appStats, 'success_total');
        $failedTotal = self::sumColumn($appStats, 'failed_total');
        $runningTotal = self::sumColumn($appStats, 'running_total');
        $todayTask = self::sumColumn($appStats, 'today_task_total');
        $todayFailed = self::sumColumn($appStats, 'today_failed_total');
        $tenantCost = self::sumColumn($appStats, 'tenant_cost_points');
        $userCharge = self::sumColumn($appStats, 'user_charge_points');
        $todayTenantCost = self::sumColumn($appStats, 'today_tenant_cost_points');
        $todayUserCharge = self::sumColumn($appStats, 'today_user_charge_points');
        $tenantAppTotal = self::tableExists('tenant_app') ? (int)Db::name('tenant_app')->count() : 0;
        $tenantAppEnabled = self::tableExists('tenant_app') ? (int)Db::name('tenant_app')->where('enable_status', 'enabled')->count() : 0;

        return [
            'time' => date('Y-m-d H:i:s'),
            'tenant_total' => (int)Tenant::count(),
            'today_new_tenant' => (int)Tenant::where('create_time', '>=', $todayStart)->count(),
            'user_total' => (int)User::count(),
            'today_new_user' => (int)User::where('create_time', '>=', $todayStart)->count(),
            'tenant_point_balance' => self::round(Tenant::sum('point_balance')),
            'today_tenant_cost_points' => $todayTenantCost,
            'today_user_charge_points' => $todayUserCharge,
            'tenant_cost_points' => $tenantCost,
            'user_charge_points' => $userCharge,
            'task_total' => $taskTotal,
            'today_task_total' => $todayTask,
            'success_total' => $successTotal,
            'failed_total' => $failedTotal,
            'today_failed_total' => $todayFailed,
            'running_total' => $runningTotal,
            'success_rate' => $taskTotal > 0 ? self::round($successTotal / $taskTotal * 100) : 0,
            'app_install_total' => self::tableExists('app') ? (int)Db::name('app')->where('status', 'installed')->count() : 0,
            'tenant_app_total' => $tenantAppTotal,
            'tenant_app_enabled' => $tenantAppEnabled,
            'membership_order_total' => self::paidOrderCount('membership_order'),
            'recharge_order_total' => self::paidOrderCount('recharge_order'),

            // 兼容旧字段
            'today_sales' => $todayUserCharge,
            'total_sales' => $userCharge,
            'today_visitor' => $todayTask,
            'total_visitor' => $taskTotal,
            'total_new_user' => (int)Tenant::count(),
            'order_num' => self::paidOrderCount('membership_order', $todayStart) + self::paidOrderCount('recharge_order', $todayStart),
            'order_sum' => self::paidOrderCount('membership_order') + self::paidOrderCount('recharge_order'),
        ];
    }

    public static function summaryCards(array $today): array
    {
        return [
            [
                'title' => '租户总数',
                'value' => $today['tenant_total'],
                'unit' => '个',
                'sub_title' => '今日新增',
                'sub_value' => '+' . $today['today_new_tenant'],
            ],
            [
                'title' => '用户总数',
                'value' => $today['user_total'],
                'unit' => '人',
                'sub_title' => '今日新增',
                'sub_value' => '+' . $today['today_new_user'],
            ],
            [
                'title' => '租户点数余额',
                'value' => $today['tenant_point_balance'],
                'unit' => '点',
                'sub_title' => '全平台余额合计',
                'sub_value' => self::formatPoints($today['tenant_point_balance']),
            ],
            [
                'title' => '今日点数消耗',
                'value' => $today['today_tenant_cost_points'],
                'unit' => '点',
                'sub_title' => '累计成本',
                'sub_value' => self::formatPoints($today['tenant_cost_points']),
            ],
            [
                'title' => '今日用户收费',
                'value' => $today['today_user_charge_points'],
                'unit' => '点',
                'sub_title' => '累计收费',
                'sub_value' => self::formatPoints($today['user_charge_points']),
            ],
            [
                'title' => '运行中任务',
                'value' => $today['running_total'],
                'unit' => '个',
                'sub_title' => '今日任务',
                'sub_value' => $today['today_task_total'],
            ],
            [
                'title' => '失败任务',
                'value' => $today['failed_total'],
                'unit' => '个',
                'sub_title' => '今日失败',
                'sub_value' => $today['today_failed_total'],
            ],
            [
                'title' => '应用开通',
                'value' => $today['tenant_app_enabled'],
                'unit' => '项',
                'sub_title' => '开通记录',
                'sub_value' => $today['tenant_app_total'],
            ],
        ];
    }

    /**
     * @notes 任务趋势
     * @return array
     * @author likeadmin
     */
    public static function trend(): array
    {
        $range = self::dateRange(self::DAYS);
        $trend = [
            'date' => array_values($range['labels']),
            'task_total' => array_fill(0, self::DAYS, 0),
            'success_total' => array_fill(0, self::DAYS, 0),
            'failed_total' => array_fill(0, self::DAYS, 0),
            'tenant_cost_points' => array_fill(0, self::DAYS, 0),
            'user_charge_points' => array_fill(0, self::DAYS, 0),
        ];

        foreach (self::APP_DEFINITIONS as $app) {
            if (!empty($app['task_table'])) {
                self::mergeTaskTrend($trend, (string)$app['task_table'], $range);
            }
            if (!empty($app['billing_table'])) {
                self::mergeBillingTrend($trend, (string)$app['billing_table'], $range);
            }
            if (!empty($app['usage_table'])) {
                self::mergeUsageTrend($trend, (string)$app['usage_table'], $range);
            }
        }

        foreach (['tenant_cost_points', 'user_charge_points'] as $key) {
            $trend[$key] = array_map([self::class, 'round'], $trend[$key]);
        }

        return $trend;
    }

    /**
     * @notes 兼容旧访问趋势字段
     * @param array|null $trend
     * @return array
     * @author 段誉
     */
    public static function visitor(?array $trend = null): array
    {
        $trend = $trend ?: self::trend();
        return [
            'date' => $trend['date'],
            'list' => [
                ['name' => '任务量', 'data' => $trend['task_total']],
                ['name' => '成功量', 'data' => $trend['success_total']],
                ['name' => '失败量', 'data' => $trend['failed_total']],
            ],
        ];
    }

    /**
     * @notes 兼容旧销售趋势字段
     * @param array|null $trend
     * @return array
     * @author 段誉
     */
    public static function sale(?array $trend = null): array
    {
        $trend = $trend ?: self::trend();
        return [
            'date' => $trend['date'],
            'list' => [
                ['name' => '租户成本', 'data' => $trend['tenant_cost_points']],
                ['name' => '用户收费', 'data' => $trend['user_charge_points']],
            ],
        ];
    }

    public static function appStats(): array
    {
        $stats = [];
        foreach (self::APP_DEFINITIONS as $app) {
            $task = !empty($app['usage_table'])
                ? self::usageStats((string)$app['usage_table'])
                : self::taskStats((string)($app['task_table'] ?? ''));
            $billing = !empty($app['usage_table'])
                ? self::usageBillingStats((string)$app['usage_table'])
                : self::billingStats((string)($app['billing_table'] ?? ''));

            $stats[] = [
                'app_code' => $app['code'],
                'app_name' => $app['name'],
                'task_total' => $task['task_total'],
                'success_total' => $task['success_total'],
                'failed_total' => $task['failed_total'],
                'running_total' => $task['running_total'],
                'today_task_total' => $task['today_task_total'],
                'today_failed_total' => $task['today_failed_total'],
                'tenant_cost_points' => $billing['tenant_cost_points'],
                'user_charge_points' => $billing['user_charge_points'],
                'today_tenant_cost_points' => $billing['today_tenant_cost_points'],
                'today_user_charge_points' => $billing['today_user_charge_points'],
            ];
        }
        return $stats;
    }

    public static function ranking(array $appStats): array
    {
        $byCharge = $appStats;
        usort($byCharge, fn($a, $b) => $b['user_charge_points'] <=> $a['user_charge_points']);
        $byTask = $appStats;
        usort($byTask, fn($a, $b) => $b['task_total'] <=> $a['task_total']);

        return [
            'by_charge' => array_slice($byCharge, 0, 6),
            'by_task' => array_slice($byTask, 0, 6),
        ];
    }

    /**
     * @notes 服务支持
     * @return array[]
     * @author 段誉
     * @date 2022/7/18 11:18
     */
    public static function support(): array
    {
        return [
            [
                'image' => FileService::getFileUrl(config('project.default_image.qq_group')),
                'title' => '官方公众号',
                'desc' => '关注官方公众号',
            ],
            [
                'image' => FileService::getFileUrl(config('project.default_image.customer_service')),
                'title' => '添加企业客服微信',
                'desc' => '想了解更多请添加客服',
            ],
        ];
    }

    private static function taskStats(string $table): array
    {
        $empty = [
            'task_total' => 0,
            'success_total' => 0,
            'failed_total' => 0,
            'running_total' => 0,
            'today_task_total' => 0,
            'today_failed_total' => 0,
        ];
        if ($table === '' || !self::tableExists($table)) {
            return $empty;
        }
        $todayStart = strtotime('today');
        $query = Db::name($table);
        if (self::hasColumn($table, 'delete_time')) {
            $query->where('delete_time', 0);
        }

        return [
            'task_total' => (int)(clone $query)->count(),
            'success_total' => (int)(clone $query)->where('status', 'success')->count(),
            'failed_total' => (int)(clone $query)->whereIn('status', ['failed', 'canceled'])->count(),
            'running_total' => (int)(clone $query)->whereIn('status', ['pending', 'running', 'processing'])->count(),
            'today_task_total' => (int)(clone $query)->where('create_time', '>=', $todayStart)->count(),
            'today_failed_total' => (int)(clone $query)->where('create_time', '>=', $todayStart)->whereIn('status', ['failed', 'canceled'])->count(),
        ];
    }

    private static function usageStats(string $table): array
    {
        $empty = [
            'task_total' => 0,
            'success_total' => 0,
            'failed_total' => 0,
            'running_total' => 0,
            'today_task_total' => 0,
            'today_failed_total' => 0,
        ];
        if ($table === '' || !self::tableExists($table)) {
            return $empty;
        }
        $todayStart = strtotime('today');
        $query = Db::name($table)->where('billing_status', 'deducted');
        $count = (int)(clone $query)->count();
        return [
            'task_total' => $count,
            'success_total' => $count,
            'failed_total' => 0,
            'running_total' => 0,
            'today_task_total' => (int)(clone $query)->where('create_time', '>=', $todayStart)->count(),
            'today_failed_total' => 0,
        ];
    }

    private static function billingStats(string $table): array
    {
        $empty = [
            'tenant_cost_points' => 0,
            'user_charge_points' => 0,
            'today_tenant_cost_points' => 0,
            'today_user_charge_points' => 0,
        ];
        if ($table === '' || !self::tableExists($table)) {
            return $empty;
        }
        $todayStart = strtotime('today');
        $query = Db::name($table);
        if (self::hasColumn($table, 'billing_status')) {
            $query->where('billing_status', 'deducted');
        }

        return [
            'tenant_cost_points' => self::round((clone $query)->sum('tenant_cost_points')),
            'user_charge_points' => self::round((clone $query)->sum('user_charge_points')),
            'today_tenant_cost_points' => self::round((clone $query)->where('create_time', '>=', $todayStart)->sum('tenant_cost_points')),
            'today_user_charge_points' => self::round((clone $query)->where('create_time', '>=', $todayStart)->sum('user_charge_points')),
        ];
    }

    private static function usageBillingStats(string $table): array
    {
        if ($table === '' || !self::tableExists($table)) {
            return [
                'tenant_cost_points' => 0,
                'user_charge_points' => 0,
                'today_tenant_cost_points' => 0,
                'today_user_charge_points' => 0,
            ];
        }
        $todayStart = strtotime('today');
        $query = Db::name($table)->where('billing_status', 'deducted');
        return [
            'tenant_cost_points' => self::round((clone $query)->sum('tenant_cost_points')),
            'user_charge_points' => self::round((clone $query)->sum('user_charge_points')),
            'today_tenant_cost_points' => self::round((clone $query)->where('create_time', '>=', $todayStart)->sum('tenant_cost_points')),
            'today_user_charge_points' => self::round((clone $query)->where('create_time', '>=', $todayStart)->sum('user_charge_points')),
        ];
    }

    private static function mergeTaskTrend(array &$trend, string $table, array $range): void
    {
        if (!self::tableExists($table)) {
            return;
        }
        $query = Db::name($table)->fieldRaw("FROM_UNIXTIME(create_time, '%Y-%m-%d') as day, COUNT(*) as task_total, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_total, SUM(CASE WHEN status IN ('failed','canceled') THEN 1 ELSE 0 END) as failed_total")
            ->whereBetween('create_time', [$range['start'], $range['end']])
            ->group('day');
        if (self::hasColumn($table, 'delete_time')) {
            $query->where('delete_time', 0);
        }
        foreach ($query->select()->toArray() as $row) {
            $index = $range['index'][$row['day'] ?? ''] ?? null;
            if ($index === null) {
                continue;
            }
            $trend['task_total'][$index] += (int)$row['task_total'];
            $trend['success_total'][$index] += (int)$row['success_total'];
            $trend['failed_total'][$index] += (int)$row['failed_total'];
        }
    }

    private static function mergeBillingTrend(array &$trend, string $table, array $range): void
    {
        if (!self::tableExists($table)) {
            return;
        }
        $query = Db::name($table)->fieldRaw("FROM_UNIXTIME(create_time, '%Y-%m-%d') as day, SUM(tenant_cost_points) as tenant_cost_points, SUM(user_charge_points) as user_charge_points")
            ->whereBetween('create_time', [$range['start'], $range['end']])
            ->group('day');
        if (self::hasColumn($table, 'billing_status')) {
            $query->where('billing_status', 'deducted');
        }
        foreach ($query->select()->toArray() as $row) {
            $index = $range['index'][$row['day'] ?? ''] ?? null;
            if ($index === null) {
                continue;
            }
            $trend['tenant_cost_points'][$index] += (float)$row['tenant_cost_points'];
            $trend['user_charge_points'][$index] += (float)$row['user_charge_points'];
        }
    }

    private static function mergeUsageTrend(array &$trend, string $table, array $range): void
    {
        if (!self::tableExists($table)) {
            return;
        }
        $query = Db::name($table)->fieldRaw("FROM_UNIXTIME(create_time, '%Y-%m-%d') as day, COUNT(*) as task_total, SUM(tenant_cost_points) as tenant_cost_points, SUM(user_charge_points) as user_charge_points")
            ->where('billing_status', 'deducted')
            ->whereBetween('create_time', [$range['start'], $range['end']])
            ->group('day');
        foreach ($query->select()->toArray() as $row) {
            $index = $range['index'][$row['day'] ?? ''] ?? null;
            if ($index === null) {
                continue;
            }
            $count = (int)$row['task_total'];
            $trend['task_total'][$index] += $count;
            $trend['success_total'][$index] += $count;
            $trend['tenant_cost_points'][$index] += (float)$row['tenant_cost_points'];
            $trend['user_charge_points'][$index] += (float)$row['user_charge_points'];
        }
    }

    private static function dateRange(int $days): array
    {
        $start = strtotime(date('Y-m-d', strtotime('-' . ($days - 1) . ' day')));
        $end = strtotime('tomorrow') - 1;
        $labels = [];
        $index = [];
        for ($i = 0; $i < $days; $i++) {
            $time = $start + $i * 86400;
            $day = date('Y-m-d', $time);
            $labels[] = date('m/d', $time);
            $index[$day] = $i;
        }
        return [
            'start' => $start,
            'end' => $end,
            'labels' => $labels,
            'index' => $index,
        ];
    }

    private static function paidOrderCount(string $table, ?int $startTime = null): int
    {
        if (!self::tableExists($table)) {
            return 0;
        }
        $query = Db::name($table)->where('pay_status', PayEnum::ISPAID);
        if ($startTime !== null) {
            $query->where('create_time', '>=', $startTime);
        }
        return (int)$query->count();
    }

    private static function sumColumn(array $rows, string $column): float|int
    {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += (float)($row[$column] ?? 0);
        }
        return self::round($sum);
    }

    private static function formatPoints(float|int $value): string
    {
        return self::round($value) . ' 点';
    }

    private static function round(mixed $value): float|int
    {
        $number = round((float)$value, 2);
        return floor($number) == $number ? (int)$number : $number;
    }

    private static function baseUrlOrigin(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (empty($parts['host'])) {
            return $url;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    private static function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $fullTable = str_replace('`', '``', self::fullTableName($table));
            $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($fullTable) . "'"));
        } catch (\Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private static function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (!self::tableExists($table)) {
            return $cache[$key] = false;
        }
        try {
            $fullTable = str_replace('`', '``', self::fullTableName($table));
            $cache[$key] = !empty(Db::query("SHOW COLUMNS FROM `" . $fullTable . "` LIKE '" . addslashes($column) . "'"));
        } catch (\Throwable) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    private static function fullTableName(string $table): string
    {
        return env('database.prefix', 'la_') . $table;
    }
}
