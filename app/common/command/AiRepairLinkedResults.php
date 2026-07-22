<?php

namespace app\common\command;

use app\common\service\ai\AiTaskBusinessResultService;
use app\common\service\ai\AiTaskResultAssetService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class AiRepairLinkedResults extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:repair-linked-results')
            ->setDescription('修复已完成市场任务的关联业务结果')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '最大修复数量', 100)
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计不执行');
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = max(1, min(500, (int)$input->getOption('limit')));
        $dryRun = (bool)$input->getOption('dry-run');
        $rows = Db::name('ai_consumption_log')->alias('c')
            ->join('ai_app_task t', 't.id = c.app_task_id')
            ->where('t.business_table', '<>', '')
            ->where('t.business_id', '>', 0)
            ->where(function ($query) {
                $query->whereIn('c.run_status', ['success', 'failed', 'canceled', 'cancelled'])
                    ->whereOr(function ($nested) {
                        $nested->whereIn('c.billing_status', ['settled', 'refunded']);
                    });
            })
            ->field('c.id,t.business_table,t.business_id')
            ->order('c.id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $repaired = 0;
        foreach ($rows as $row) {
            if (!$dryRun) {
                $consumptionId = (int)$row['id'];
                AiTaskResultAssetService::recordConsumptionAssets($consumptionId, AiTaskBusinessResultService::requiresForcedTransfer($consumptionId));
                AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
            }
            $repaired++;
        }
        $output->writeln(($dryRun ? 'matched: ' : 'repaired: ') . $repaired);
        return 0;
    }
}
