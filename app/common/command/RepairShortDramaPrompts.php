<?php

namespace app\common\command;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class RepairShortDramaPrompts extends Command
{
    protected function configure()
    {
        $this->setName('short-drama:repair-prompts')
            ->addOption('project_id', null, Option::VALUE_OPTIONAL, 'Project ID')
            ->addOption('task_id', null, Option::VALUE_OPTIONAL, 'Script task ID')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Maximum rows per source')
            ->addOption('apply', null, Option::VALUE_NONE, 'Persist repaired prompt data')
            ->setDescription('Audit or repair legacy short drama image and video prompts');
    }

    protected function execute(Input $input, Output $output)
    {
        $apply = (bool)$input->getOption('apply');
        $result = AigcShortDramaService::repairLegacyPromptData(
            max(0, (int)$input->getOption('project_id')),
            trim((string)$input->getOption('task_id')),
            max(0, (int)$input->getOption('limit')),
            $apply
        );
        foreach ($result as $key => $value) {
            $output->writeln($key . '=' . (int)$value);
        }
        $output->writeln($apply ? 'mode=applied' : 'mode=audit');
        return true;
    }
}
