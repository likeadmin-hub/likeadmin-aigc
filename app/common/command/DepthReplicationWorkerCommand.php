<?php
namespace app\common\command;

use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_depth_replication\{DepthJobs,DepthWorker};
use think\console\{Command,Input,Output};
use think\console\input\Option;

final class DepthReplicationWorkerCommand extends Command
{
    protected function configure()
    {
        $this->setName('depth-replication:worker')->addOption('once',null,Option::VALUE_NONE,'Handle one queued job')->setDescription('Analyze and relight owned videos');
    }

    protected function execute(Input $input, Output $output)
    {
        $once = $input->getOption('once');
        do {
            if (function_exists('aigc_runtime_generation_changed') && aigc_runtime_generation_changed()) break;
            $worked = AppRegistryService::isInstalled(DepthJobs::APP) && DepthWorker::tick();
            if ($once) { $output->writeln('processed='.(int)$worked); break; }
            if (!$worked) sleep(3);
        } while (true);
        return 0;
    }
}

