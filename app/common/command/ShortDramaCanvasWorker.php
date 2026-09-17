<?php

namespace app\common\command;

use app\common\service\app\aigc_short_drama\canvas\CanvasAgentService;
use app\common\service\app\aigc_short_drama\canvas\CanvasExecutionRuntime;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * Durable worker for short-drama canvas Agent runs. It claims persisted work,
 * invokes only the reviewed short-drama resource adapter, and can recover
 * leases after a worker interruption without re-submitting an unknown call.
 */
class ShortDramaCanvasWorker extends Command
{
    protected function configure(): void
    {
        $this->setName('short-drama:canvas-worker')
            ->setDescription('Run and recover durable short-drama canvas Agent work')
            ->addOption('once', null, Option::VALUE_NONE, 'Run one scan and exit')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Maximum runs per scan', 20);
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!(bool)config('short_drama_canvas.execution_ready', false)) {
            $output->writeln('Short-drama canvas Agent execution is disabled; no work claimed.');
            return 0;
        }
        $limit = max(1, min(500, (int)$input->getOption('limit')));
        $stopping = false;
        $stop = static function () use (&$stopping): void { $stopping = true; };
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        do {
            try {
                $now = time();
                $scopes = Db::name('aigc_short_drama_canvas_run')
                    ->whereIn('status', ['running', 'cancel_requested'])
                    ->where('lease_expires_at', '<=', $now)
                    ->field('tenant_id,user_id')->group('tenant_id,user_id')->limit($limit)->select()->toArray();
                $recovered = 0;
                foreach ($scopes as $scope) {
                    $recovered += (new CanvasAgentService((int)$scope['tenant_id'], (int)$scope['user_id']))->recoverExpired($now);
                }
                $pending = Db::name('aigc_short_drama_canvas_run')->where('status', 'pending')
                    ->field('tenant_id,user_id,workspace_id,id')->order('id')->limit($limit)->select()->toArray();
                $claimed = 0;
                foreach ($pending as $row) {
                    try {
                        $tenantId = (int)$row['tenant_id']; $userId = (int)$row['user_id'];
                        $service = new CanvasAgentService($tenantId, $userId);
                        $provider = CanvasExecutionRuntime::provider($tenantId, $userId);
                        if (!$provider->isReady()) continue;
                        $token = $service->claim((int)$row['workspace_id'], (int)$row['id'], time());
                        if (!$token) continue;
                        $claimed++;
                        $service->execute((int)$row['workspace_id'], (int)$row['id'], $token,
                            static fn(array $request): array => $provider->invokeAgent($request));
                    } catch (\Throwable $runError) {
                        $output->writeln('short-drama canvas run error: ' . mb_substr($runError->getMessage(), 0, 300, 'UTF-8'));
                    }
                }
                $output->writeln(sprintf('short-drama canvas worker: recovery_scopes=%d recovered=%d claimed=%d', count($scopes), $recovered, $claimed));
                if ($input->getOption('once')) return 0;
                sleep(($scopes === [] && $pending === []) ? 10 : 1);
            } catch (\Throwable $e) {
                $output->writeln('short-drama canvas worker error: ' . mb_substr($e->getMessage(), 0, 300, 'UTF-8'));
                if ($input->getOption('once')) return 1;
                sleep(5);
            }
        } while (!$stopping);
        return 0;
    }
}
