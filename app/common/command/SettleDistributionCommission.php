<?php

namespace app\common\command;

use app\common\service\distribution\DistributionService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class SettleDistributionCommission extends Command
{
    protected function configure()
    {
        $this->setName('distribution:settle')->setDescription('结算到期分销佣金');
    }

    protected function execute(Input $input, Output $output)
    {
        $count = DistributionService::settleDue(1000);
        $output->writeln('settled: ' . $count);
        return self::SUCCESS;
    }
}
