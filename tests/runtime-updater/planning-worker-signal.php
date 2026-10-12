<?php
declare(strict_types=1);

// Exercise the real worker startup with CLI functions individually disabled.
// The runtime generation boundary ends the command before it can query tasks.
namespace app\common\service\app\aigc_short_drama {
    final class ShortDramaWorkerMemory
    {
        public static function release(): void {}
    }
}

namespace {
    $root = getenv('AIGC_WORKER_BASELINE_ROOT') ?: dirname(__DIR__, 2);
    $loader = require $root . '/vendor/autoload.php';
    $loader->setPsr4('app\\', [$root . '/app']);

    function aigc_runtime_generation_changed(): bool { return true; }

    try {
        $mode = $argv[1] ?? '';
        $expected = [
            'signal-disabled' => [true, false],
            'async-disabled' => [false, true],
            'both-disabled' => [false, false],
            'enabled' => [true, true],
        ][$mode] ?? null;
        if ($expected !== [function_exists('pcntl_async_signals'), function_exists('pcntl_signal')]) {
            throw new RuntimeException('CLI signal fixture does not match requested case');
        }
        $worker = new \app\common\command\ShortDramaPlanningWorker();
        $method = new ReflectionMethod($worker, 'execute');
        $method->setAccessible(true);
        $result = $method->invoke($worker, new \think\console\Input([]), new \think\console\Output('buffer'));
        if ($result !== 0) {
            throw new RuntimeException('Worker failed before runtime retirement boundary');
        }
        if ($mode === 'enabled') {
            foreach ([SIGTERM, SIGINT] as $signal) {
                if (!is_callable(pcntl_signal_get_handler($signal))) {
                    throw new RuntimeException('Worker did not register its graceful stop handler');
                }
            }
        }
        echo "PASS: planning worker starts with $mode\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
