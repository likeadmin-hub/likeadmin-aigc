<?php

namespace app\common\command;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class AiUsageReconcile extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:usage_reconcile')
            ->setDescription('补偿刷新AIGC任务与消耗日志')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '最大刷新任务数', 20);
    }

    protected function execute(Input $input, Output $output): int
    {
        $count = AigcImageService::refreshPendingTasks((int)$input->getOption('limit'));
        $videoCount = 0;
        if (self::hasColumn('la_aigc_video_task', 'consumption_id')) {
            $videoTasks = AigcVideoTask::where('provider', 'power_market')
                ->whereIn('status', ['running', 'success'])
                ->where('consumption_id', '>', 0)
                ->where('delete_time', 0)
                ->order('id', 'asc')
                ->limit((int)$input->getOption('limit'))
                ->select();
            foreach ($videoTasks as $task) {
                try {
                    AigcVideoService::refreshMarketTask((int)$task['tenant_id'], (int)$task['id'], (int)$task['user_id']);
                    $videoCount++;
                } catch (\Throwable) {
                    // The next compensation pass will retry transient supplier failures.
                }
            }
        }
        $shortDramaVideoCount = self::hasColumn('la_aigc_short_drama_generation_task', 'consumption_id')
            ? AigcShortDramaService::refreshMarketVideoTasks((int)$input->getOption('limit'))
            : 0;
        $shortDramaUsageCount = self::hasColumn('la_aigc_short_drama_generation_task', 'consumption_id')
            ? AigcShortDramaService::refreshMarketUsageTasks((int)$input->getOption('limit'))
            : 0;
        $purged = \app\common\service\ai\AiUsageService::purgeExpiredPayloads();
        $output->writeln('refreshed: ' . $count . ', video_refreshed: ' . $videoCount . ', short_drama_video_refreshed: ' . $shortDramaVideoCount . ', short_drama_usage_refreshed: ' . $shortDramaUsageCount . ', purged_payloads: ' . $purged);
        return 0;
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            // MySQL does not accept a prepared placeholder in SHOW COLUMNS
            // statements under ThinkPHP's raw-query path. This guard used to
            // always return false and skipped all market-video reconciliation.
            $safeTable = str_replace('`', '', $table);
            $safeColumn = str_replace(["'", '\\'], ['', ''], $column);
            $rows = Db::query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            return $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
