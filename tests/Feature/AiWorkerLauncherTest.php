<?php

use PHPUnit\Framework\TestCase;

final class AiWorkerLauncherTest extends TestCase
{
    public function testIndependentWorkersRestartAndStopThroughOneLauncher(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            $this->markTestSkipped('Requires Linux process control and flock');
        }
        $dir = sys_get_temp_dir() . '/ai-launcher-test-' . bin2hex(random_bytes(8));
        mkdir($dir . '/scripts', 0700, true);
        mkdir($dir . '/runtime', 0700);
        copy(dirname(__DIR__, 2) . '/scripts/start-ai-task-worker.sh', $dir . '/scripts/start-ai-task-worker.sh');
        // No framework, DB, provider or billing: these are idle fake workers.
        file_put_contents($dir . '/think', <<<'PHP'
<?php
pcntl_async_signals(true);
$running = true;
pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
$kind = $argv[1] === 'ai:task-worker' ? 'result' : 'episode';
file_put_contents(__DIR__ . '/' . $kind . '.pid', (string)getmypid());
while ($running) usleep(100000);
PHP
        );
        $env = array_merge(getenv(), ['PHP_BIN' => PHP_BINARY]);
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open(['/bin/sh', $dir . '/scripts/start-ai-task-worker.sh'], $descriptors, $pipes, $dir, $env);
        $pids = [];
        try {
            $readPid = static fn(string $kind): int => is_file($dir . '/' . $kind . '.pid') ? (int)file_get_contents($dir . '/' . $kind . '.pid') : 0;
            $this->waitFor(static fn(): bool => $readPid('result') > 0 && $readPid('episode') > 0);
            $result = $pids[] = $readPid('result');
            $episode = $pids[] = $readPid('episode');
            $this->assertNotSame($result, $episode);
            $duplicate = proc_open(['/bin/sh', $dir . '/scripts/start-ai-task-worker.sh'], $descriptors, $pipes, $dir, $env);
            $this->assertSame(1, proc_close($duplicate), 'A second launcher must not duplicate workers');
            posix_kill($episode, SIGKILL);
            $this->waitFor(static fn(): bool => $readPid('episode') !== $episode);
            $pids[] = $readPid('episode');
            $this->assertSame($result, $readPid('result'), 'Episode restart must not restart the result worker');
            posix_kill($result, SIGKILL);
            $this->waitFor(static fn(): bool => $readPid('result') !== $result);
            $pids[] = $readPid('result');
            proc_terminate($process, SIGTERM);
            $this->waitFor(static fn(): bool => !proc_get_status($process)['running']);
            foreach (array_slice($pids, 2) as $pid) $this->assertFalse(posix_kill($pid, 0), 'Stopping the launcher must stop both workers');
        } finally {
            proc_terminate($process, SIGTERM);
            foreach ($pids as $pid) if (posix_kill($pid, 0)) posix_kill($pid, SIGTERM);
            proc_close($process);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($dir);
        }
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 10;
        while (!$condition() && microtime(true) < $deadline) usleep(50000);
        $this->assertTrue($condition(), 'Process transition timed out');
    }
}
