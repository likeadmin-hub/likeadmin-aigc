<?php

namespace app\common\command;

use app\common\service\ai\MarketTaskReconciliationService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class MarketTaskAudit extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:market-audit')
            ->setDescription('Read-only reconciliation for power-market tasks')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant id', 0)
            ->addOption('app-code', null, Option::VALUE_OPTIONAL, 'Application code', '')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Maximum records', 500)
            ->addOption('repair-assets', null, Option::VALUE_NONE, 'Repair durable result-asset rows')
            ->addOption('apply', null, Option::VALUE_NONE, 'Apply a requested repair; otherwise remain read-only')
            ->addOption('fail-on-issues', null, Option::VALUE_NONE, 'Return non-zero when anomalies exist');
    }

    protected function execute(Input $input, Output $output): int
    {
        if ($input->getOption('repair-assets')) {
            $report = MarketTaskReconciliationService::repairAssets(
                max(0, (int)$input->getOption('tenant-id')),
                trim((string)$input->getOption('app-code')),
                max(1, (int)$input->getOption('limit')),
                (bool)$input->getOption('apply')
            );
            $output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 0;
        }
        $report = MarketTaskReconciliationService::audit(
            max(0, (int)$input->getOption('tenant-id')),
            trim((string)$input->getOption('app-code')),
            max(1, (int)$input->getOption('limit'))
        );
        $output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return !empty($report['issue_count']) && $input->getOption('fail-on-issues') ? 1 : 0;
    }
}
