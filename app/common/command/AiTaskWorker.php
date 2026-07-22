<?php

namespace app\common\command;

use app\common\service\ai\AiTaskJobService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class AiTaskWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('ai:task-worker')
            ->setDescription('处理 AI 异步结果、转存和结算任务')
            ->addOption('worker', null, Option::VALUE_OPTIONAL, 'Worker 类型', 'result')
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, '空队列休眠秒数', 1)
            ->addOption('lease', null, Option::VALUE_OPTIONAL, '任务租约秒数', 90)
            ->addOption('batch', null, Option::VALUE_OPTIONAL, '单轮领取数量', 20);
    }

    protected function execute(Input $input, Output $output): int
    {
        if ((string)$input->getOption('worker') !== 'result') {
            $output->writeln('Only result worker is supported.');
            return 1;
        }
        $sleep = max(1, (int)$input->getOption('sleep'));
        $lease = max(10, (int)$input->getOption('lease'));
        $batch = max(1, min(100, (int)$input->getOption('batch')));
        $worker = gethostname() . ':' . getmypid();
        $running = true;
        $stop = static function () use (&$running): void { $running = false; };
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        $output->writeln('AI result worker started: ' . $worker);
        try {
            while ($running) {
                $jobs = AiTaskJobService::claim($worker, $lease, $batch);
                if ($jobs === []) {
                    sleep($sleep);
                    continue;
                }
                foreach ($jobs as $job) {
                    try {
                        $finished = AiTaskJobService::run($job);
                        if ($finished) {
                            AiTaskJobService::succeed($job);
                        } else {
                            AiTaskJobService::reschedule($job, 5);
                        }
                    } catch (\Throwable $e) {
                        AiTaskJobService::retry($job, $e);
                        $output->writeln(sprintf('[job:%d] %s', (int)$job['id'], $e->getMessage()));
                    }
                }
            }
        } finally {
            $pidFile = trim((string)getenv('AI_TASK_WORKER_PID_FILE'));
            if ($pidFile !== '' && is_file($pidFile) && trim((string)@file_get_contents($pidFile)) === (string)getmypid()) {
                @unlink($pidFile);
            }
        }
        $output->writeln('AI result worker stopped: ' . $worker);
        return 0;
    }
}
