<?php
namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class ShortDramaPlanningWorker extends Command
{
    protected function configure()
    {
        $this->setName('short-drama:planning-worker')->addOption('once', null, Option::VALUE_NONE, 'Process one planning task')
            ->addOption('task', null, Option::VALUE_OPTIONAL, 'Restrict to a task ID')
            ->setDescription('Run story, outline and v3 standalone script tasks independently of the browser');
    }
    protected function execute(Input $input, Output $output)
    {
        @set_time_limit(0);
        $stopping = false;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$stopping) { $stopping = true; };
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
            $processed = \app\common\service\app\aigc_short_drama\ShortDramaPlanningDispatch::tick(
                (string)$input->getOption('task'), 0, null, static function () use (&$stopping): bool { return $stopping; }
            );
            if ($input->getOption('once')) { $output->writeln('processed=' . (int)$processed); return 0; }
            if (!$processed) sleep(3);
            } catch (\Throwable $error) {
                // Never turn a setup/database failure into a successful task or a tight retry loop.
                $output->writeln('Planning worker error: ' . $error->getMessage());
                if ($input->getOption('once')) return 1;
                sleep(3);
            }
        } while (!$stopping);
        return 0;
    }
}
