<?php
declare(strict_types=1);
namespace app\common\service\app;
use RuntimeException;

/** Old PHP daemons keep loaded code after a checkout; require them stopped before closure. */
final class RetiredAppWorkerGuard
{
    public static function matches(array $arguments, string $root): bool
    {
        $think = rtrim($root, '/').'/think';
        return in_array('micro-video:worker', $arguments, true) && in_array($think, $arguments, true);
    }

    public static function assertStopped(string $root): void
    {
        if (!is_dir('/proc')) throw new RuntimeException('请在 Linux 主机核实并停止旧微视频守护进程后执行');
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $command = @file_get_contents($path);
            if ($command === false || $command === '') continue;
            if (self::matches(explode("\0", $command), $root)) throw new RuntimeException('请先停止并禁用本项目的旧微视频守护进程，再执行核对或退役');
        }
    }
}
