<?php

namespace app\common\command;

use app\common\model\ai\AiConsumptionLog;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\ai\AiTaskJobService;
use app\common\service\ai\AiMarketTaskRuntimeService;
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
        \app\common\service\app\aigc_copywriting\CopywritingRecovery::sweep($limit);
        $rows = AiConsumptionLog::whereIn('run_status', ['reserved', 'submitted', 'running', 'success', 'music_reserved', 'music_submitting', 'music_running'])
            ->whereIn('billing_status', ['reserved', 'pending_usage'])
            ->order(['refresh_requested_at' => 'asc', 'id' => 'asc'])
            ->limit($limit)
            ->select();
        $count = 0;
        foreach ($rows as $row) {
            if ($row['app_code'] === 'aigc_copywriting') {
                $task=\think\facade\Db::name('aigc_copywriting_task')->where('consumption_id',(int)$row['id'])->find();
                if($task) \app\common\service\app\aigc_copywriting\CopywritingRecovery::reconcile((int)$task['tenant_id'],(int)$task['id']);
                continue;
            }
            if($row['app_code']==='aigc_llm'&&$row['action_code']==='agent_speech'){
                \app\common\service\app\aigc_llm\agent\AgentSpeechTool::reconcile((int)$row['id'],(int)$row['tenant_id']);
                $count++;continue;
            }
            if($row['app_code']==='aigc_llm'&&$row['action_code']==='agent_transcribe'){
                \app\common\service\app\aigc_llm\agent\AgentTranscriptionRecovery::reconcile((int)$row['id'],(int)$row['tenant_id']);
                $count++;continue;
            }
            self::enqueueResultQuery($row->toArray());
            AiConsumptionLog::where('id', (int)$row['id'])->update(['refresh_requested_at' => time(), 'update_time' => time()]);
            $count++;
        }
        $clip=\app\common\service\app\smart_clip\SmartClipTaskRuntime::sweep($limit);
        $legacy = AigcShortDramaService::refreshLegacyGenerationTasks($limit);
        $drained = AiTaskJobService::drain(
            'reconcile:' . (gethostname() ?: 'host') . ':' . getmypid(),
            60,
            $limit
        );
        $purged = \app\common\service\ai\AiUsageService::purgeExpiredPayloads();
        $output->writeln('clip_enqueued: ' . $clip . ', enqueued: ' . $count . ', legacy_refreshed: ' . $legacy . ', queue_processed: ' . $drained['processed'] . ', queue_waiting: ' . $drained['waiting'] . ', queue_retried: ' . $drained['retried'] . ', purged_payloads: ' . $purged);
        return 0;
    }

    private static function enqueueResultQuery(array $consumption): void
    {
        $snapshot = (array)$consumption['price_snapshot'];
        if (($snapshot['runtime_adapter'] ?? '') === 'music_operation') {
            AiTaskJobService::enqueueMusicOperation((int)$consumption['id']);
        } elseif (AiMarketTaskRuntimeService::awaitsUsageSettlement($consumption)) {
            // Older workers may have completed the query job before actual usage arrived.
            AiTaskJobService::requeueQueryResult((int)$consumption['id'], 10);
        } else {
            AiTaskJobService::enqueueQueryResult((int)$consumption['id'], 10, true);
        }
    }
}
