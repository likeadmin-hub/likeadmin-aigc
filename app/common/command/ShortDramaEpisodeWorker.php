<?php

namespace app\common\command;

use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class ShortDramaEpisodeWorker extends Command
{
    protected function configure()
    {
        $this->setName('short-drama:episode-worker')
            ->addOption('once', null, Option::VALUE_NONE, 'Process at most one episode')
            ->addOption('tenant', null, Option::VALUE_REQUIRED, 'Restrict to a tenant', 0)
            ->addOption('project', null, Option::VALUE_REQUIRED, 'Restrict to a series project', 0)
            ->setDescription('Generate confirmed short drama episodes sequentially');
    }

    protected function execute(Input $input, Output $output)
    {
        foreach(["tenant", "project"] as $key)if(filter_var($input->getOption($key),FILTER_VALIDATE_INT)===false)throw new \InvalidArgumentException('Invalid worker scope');
        $tenant=(int)$input->getOption('tenant');$project=(int)$input->getOption('project');
        if($tenant<0 || $project<0 || ($project>0 && $tenant<=0))throw new \InvalidArgumentException('A scoped project requires its tenant');
        @set_time_limit(0);
        $running = true;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$running) { $running = false; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        do {
            \app\common\service\app\aigc_short_drama\ShortDramaWorkerMemory::release();
                if (function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed()) {
                    $output->writeln('Runtime generation changed; worker drained and stopped.');
                    break;
                }
            try {
                $processed = ShortDramaEpisodeService::tick($tenant, $project);
            } catch (\Throwable $e) {
                \think\facade\Log::error('Episode worker: ' . $e->getMessage());
                if ($input->getOption('once')) throw $e;
                $processed = 0;
            }
            if ($input->getOption('once')) { $output->writeln('processed=' . $processed); break; }
            if (!$processed && $running) sleep(3);
        } while ($running);
        return 0;
    }
}
