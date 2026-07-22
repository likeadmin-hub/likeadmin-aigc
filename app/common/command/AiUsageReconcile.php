<?php

namespace app\common\command;

use app\common\model\ai\AiConsumptionLog;
use app\common\service\ai\AiTaskJobService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class AiUsageReconcile extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:usage_reconcile')
            ->setDescription('补投缺失或过期的 AI 结果任务')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '最大补投任务数', 100);
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, min(500, (int)$input->getOption('limit')));
        $rows = AiConsumptionLog::whereIn('run_status', ['reserved', 'submitted', 'running', 'success'])
            ->whereIn('billing_status', ['reserved', 'pending_usage'])
            ->order(['refresh_requested_at' => 'asc', 'id' => 'asc'])
            ->limit($limit)
            ->select();
        $count = 0;
        foreach ($rows as $row) {
            AiTaskJobService::enqueueQueryResult((int)$row['id'], 10, true);
            AiConsumptionLog::where('id', (int)$row['id'])->update(['refresh_requested_at' => time(), 'update_time' => time()]);
            $count++;
        }
        $purged = \app\common\service\ai\AiUsageService::purgeExpiredPayloads();
        $output->writeln('enqueued: ' . $count . ', purged_payloads: ' . $purged);
        return 0;
    }
}
