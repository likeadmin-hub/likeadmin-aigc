<?php

namespace app\common\command;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiConsumptionEvent;
use app\common\service\ai\AiTaskJobService;
use app\common\service\point\PointService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/** Restores explicitly selected tasks that were incorrectly refunded by a local timeout. */
class AiRecoverTimeoutTasks extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:recover-timeout-tasks')
            ->setDescription('恢复被本地 timeout 错误退款且仍有上游任务号的 AI 任务')
            ->addOption('ids', null, Option::VALUE_REQUIRED, '消耗记录 ID，逗号分隔')
            ->addOption('apply', null, Option::VALUE_NONE, '执行恢复；默认仅审计');
    }

    protected function execute(Input $input, Output $output): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$input->getOption('ids'))))));
        if ($ids === []) {
            $output->writeln('必须指定 --ids=56,57');
            return 1;
        }

        $apply = (bool)$input->getOption('apply');
        $rows = AiConsumptionLog::whereIn('id', $ids)->order('id', 'asc')->select()->toArray();
        $found = array_column($rows, null, 'id');
        $recoverable = [];
        foreach ($ids as $id) {
            $row = $found[$id] ?? null;
            if ($row === null) {
                $output->writeln('skip ' . $id . ': 不存在');
                continue;
            }
            $valid = (string)$row['error_code'] === 'timeout'
                && (string)$row['billing_status'] === 'refunded'
                && trim((string)$row['upstream_task_id']) !== '';
            if (!$valid) {
                $output->writeln('skip ' . $id . ': 不符合 timeout/refunded/上游任务号 条件');
                continue;
            }
            $recoverable[] = $id;
            $output->writeln(sprintf(
                'match %d: task=%s tenant=%s user=%s reserve=%s/%s',
                $id,
                (string)$row['upstream_task_id'],
                (string)$row['tenant_id'],
                (string)$row['user_id'],
                (string)$row['reserved_tenant_cost'],
                (string)$row['reserved_user_price']
            ));
        }
        if (!$apply) {
            $output->writeln('dry-run matched: ' . count($recoverable));
            return 0;
        }

        $recovered = [];
        foreach ($recoverable as $id) {
            Db::transaction(function () use ($id, &$recovered): void {
                $consumption = AiConsumptionLog::where('id', $id)->lock(true)->findOrEmpty();
                if ($consumption->isEmpty()
                    || (string)$consumption['error_code'] !== 'timeout'
                    || (string)$consumption['billing_status'] !== 'refunded'
                    || trim((string)$consumption['upstream_task_id']) === '') {
                    return;
                }
                $task = AiAppTask::where('id', (int)$consumption['app_task_id'])->lock(true)->findOrEmpty();
                if ($task->isEmpty()) {
                    throw new \RuntimeException('关联 AI 应用任务不存在: ' . $id);
                }

                $tenantPoints = (float)$consumption['reserved_tenant_cost'];
                $userPoints = (float)$consumption['reserved_user_price'];
                if ($tenantPoints > 0 || $userPoints > 0) {
                    PointService::reserveBusinessAmountsInCurrentTransaction(
                        (int)$consumption['tenant_id'],
                        (int)$consumption['user_id'],
                        $tenantPoints,
                        $userPoints,
                        (string)$consumption['consume_no'] . '-timeout-recover-reserve',
                        '本地超时误退款恢复预占',
                        [
                            'app_code' => (string)$task['app_code'],
                            'app_task_id' => (int)$task['id'],
                            'app_task_no' => (string)$task['task_no'],
                            'consumption_id' => (int)$consumption['id'],
                            'consume_no' => (string)$consumption['consume_no'],
                            'billing_stage' => 'timeout_recovered_reserved',
                        ]
                    );
                }

                $now = time();
                $consumption->save([
                    'run_status' => 'running',
                    'billing_status' => 'reserved',
                    'error_code' => '',
                    'error_message' => '',
                    'refresh_requested_at' => 0,
                    'finish_time' => 0,
                    'update_time' => $now,
                ]);
                $task->save([
                    'status' => 'running',
                    'progress' => 10,
                    'result_summary' => [],
                    'finish_time' => 0,
                    'update_time' => $now,
                ]);
                AiConsumptionEvent::create([
                    'consumption_id' => $id,
                    'event_type' => 'recover',
                    'event_status' => 'success',
                    'attempt_no' => 1,
                    'payload_summary' => ['reason' => 'local_timeout_refund_recovered'],
                    'payload_ciphertext' => '',
                    'http_status' => 0,
                    'elapsed_ms' => 0,
                    'create_time' => $now,
                ]);
                $recovered[] = $id;
            });
        }
        foreach ($recovered as $id) {
            AiTaskJobService::enqueueQueryResult($id, 100, true);
            $output->writeln('recovered and queued: ' . $id);
        }
        $output->writeln('recovered: ' . count($recovered));
        return 0;
    }
}
