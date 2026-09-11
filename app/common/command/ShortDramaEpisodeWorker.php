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
            ->setDescription('Generate confirmed short drama episodes sequentially');
    }

    protected function execute(Input $input, Output $output)
    {
        @set_time_limit(0);
        $running = true;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$running) { $running = false; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        do {
            try {
                $processed = ShortDramaEpisodeService::tick();
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
