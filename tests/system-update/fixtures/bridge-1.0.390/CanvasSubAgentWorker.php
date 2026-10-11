<?php

namespace app\common\command;

use app\common\service\app\aigc_canvas\agent\runtime\SubAgentTaskService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/** Consumes durable canvas sub-agent rows outside request/SSE workers. */
class CanvasSubAgentWorker extends Command
{
    private const MIN_LEASE_SECONDS = 150;

    protected function configure(): void
    {
        $this->setName('canvas:subagent-worker')
            ->setDescription('Process durable AIGC canvas sub-agent tasks')
            ->addOption('worker', null, Option::VALUE_OPTIONAL, 'Worker name prefix', 'subagent')
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Idle sleep seconds', 1)
            ->addOption('lease', null, Option::VALUE_OPTIONAL, 'Task lease seconds', 180)
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Tasks to process with --once', 20)
            ->addOption('once', null, Option::VALUE_NONE, 'Process available tasks once, then exit');
    }

    protected function execute(Input $input, Output $output): int
    {
        $sleep = max(1, min(60, (int)$input->getOption('sleep')));
        $lease = max(self::MIN_LEASE_SECONDS, min(900, (int)$input->getOption('lease')));
        $limit = max(1, min(100, (int)$input->getOption('limit')));
        $once = (bool)$input->getOption('once');
        $prefix = preg_replace('/[^a-zA-Z0-9_.-]/', '-', (string)$input->getOption('worker')) ?: 'subagent';
        $worker = sprintf('%s:%s:%d', $prefix, gethostname() ?: 'host', getmypid());
        $running = true;
        $stop = static function () use (&$running): void { $running = false; };

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        $processed = 0;
        $output->writeln(sprintf('Canvas sub-agent worker started: %s lease=%ds mode=%s', $worker, $lease, $once ? 'once' : 'daemon'));
        while ($running && (!$once || $processed < $limit)) {
            try {
                $recovered = SubAgentTaskService::recoverExpiredLeases();
                if ($recovered > 0) {
                    $output->writeln(sprintf('[worker] marked %d exhausted lease(s) as failed', $recovered));
                }

                // A sub-agent call may take up to 120 seconds. Claim exactly one
                // task so later tasks never lose their lease while waiting in PHP.
                $tasks = SubAgentTaskService::claim($worker, $lease, 1);
                if ($tasks === []) {
                    if ($once) {
                        break;
                    }
                    sleep($sleep);
                    continue;
                }

                $task = $tasks[0];
                $output->writeln(sprintf('[subtask:%d] start agent=%s attempt=%d', (int)$task['id'], (string)$task['agent_code'], (int)$task['attempts']));
                SubAgentTaskService::execute($task);
                $processed++;
                $output->writeln(sprintf('[subtask:%d] finished', (int)$task['id']));
            } catch (\Throwable $e) {
                $output->writeln('[worker] ' . mb_substr($e->getMessage(), 0, 500, 'UTF-8'));
                if ($once) {
                    return 1;
                }
                sleep($sleep);
            }
        }

        $output->writeln(sprintf('Canvas sub-agent worker stopped: %s processed=%d', $worker, $processed));
        return 0;
    }
}
