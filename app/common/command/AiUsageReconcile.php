<?php

namespace app\common\command;

use app\common\service\app\aigc_image\AigcImageService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

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
        $purged = \app\common\service\ai\AiUsageService::purgeExpiredPayloads();
        $output->writeln('refreshed: ' . $count . ', purged_payloads: ' . $purged);
        return self::SUCCESS;
    }
}
