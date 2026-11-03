<?php
declare(strict_types=1);

// Exercise the real command with an in-memory worker, without DB/provider calls.
namespace app\common\service\app\aigc_copywriting {
    final class CopywritingRecovery {
        public static int $sweeps = 0;
        public static function sweep(): void { self::$sweeps++; }
    }
    final class CopywritingWorkerService {
        public static int $claims = 0;
        public static bool $finished = false;
        public static function claim(string $owner, int $lease, int $limit): array {
            if (++self::$claims > 1) throw new \RuntimeException('Claimed another task after TERM');
            return [['id' => 42]];
        }
        public function run(int $id, string $owner): void {
            if ($id !== 42) throw new \RuntimeException('Wrong task');
            // Deliver TERM while generation is active; returning is the durable write.
            posix_kill(getmypid(), SIGTERM);
            self::$finished = true;
        }
    }
}
namespace {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
        throw new \RuntimeException('Run with pcntl and posix enabled');
    }
    $command = new \app\common\command\CopywritingWorker();
    $code = $command->run(new \think\console\Input([]), new \think\console\Output());
    if ($code !== 0 || !\app\common\service\app\aigc_copywriting\CopywritingWorkerService::$finished
        || \app\common\service\app\aigc_copywriting\CopywritingWorkerService::$claims !== 1) {
        throw new \RuntimeException('TERM did not drain exactly the active task');
    }
    echo "PASS copywriting command completes active task on TERM without taking another task\n";
}
