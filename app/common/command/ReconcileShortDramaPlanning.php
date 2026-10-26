<?php
namespace app\common\command;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningState;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/** Explicit, bounded maintenance. Dry-run by default; never submits or refunds a task. */
class ReconcileShortDramaPlanning extends Command
{
    protected function configure()
    {
        $this->setName('short-drama:reconcile-planning')
            ->addOption('tenant', null, Option::VALUE_REQUIRED, 'Tenant ID')
            ->addOption('user', null, Option::VALUE_REQUIRED, 'Owner user ID')
            ->addOption('task', null, Option::VALUE_REQUIRED, 'Exact script task ID')
            ->addOption('apply', null, Option::VALUE_NONE, 'Apply the listed state-only changes')
            ->setDescription('Reconcile one owned script task without generation, billing or content changes');
    }
    protected function execute(Input $input, Output $output)
    {
        $tenant=(int)$input->getOption('tenant'); $user=(int)$input->getOption('user');
        $task=trim((string)$input->getOption('task'));
        if ($tenant <= 0 || $user <= 0 || $task === '') {
            $output->writeln('Required: --tenant, --user and --task. No data changed.');
            return 1;
        }
        $result=ShortDramaPlanningState::reconcile($tenant,$user,$task,(bool)$input->getOption('apply'));
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ($result['reason'] ?? '') === 'not_found' ? 1 : 0;
    }
}
