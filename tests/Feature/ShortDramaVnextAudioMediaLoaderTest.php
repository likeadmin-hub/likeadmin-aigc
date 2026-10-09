<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\AudioMediaLoader;
use app\common\service\app\aigc_short_drama\vnext\AudioMediaProbe;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextAudioMediaLoaderTest extends TestCase
{
    public function testLocalCopyPreservesBytesAndRejectsChangedObjectWithNoPartialFile(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'voice_source_'); $target = tempnam(sys_get_temp_dir(), 'voice_target_');
        try {
            $bytes = str_repeat("\x00\xff\x01", 6000); file_put_contents($source, $bytes);
            AudioMediaLoader::local($source, $target, strlen($bytes));
            self::assertSame($bytes, file_get_contents($target));
            foreach ([strlen($bytes) - 1, strlen($bytes) + 1, 0, AudioMediaProbe::MAX_BYTES + 1] as $expected) {
                // Invalid budgets are rejected before opening the target; caller owns its cleanup.
                if (is_file($target)) unlink($target);
                try { AudioMediaLoader::local($source, $target, $expected); self::fail('Changed file accepted'); }
                catch (\RuntimeException $error) { self::assertContains($error->getCode(), [409, 413]); }
                self::assertFileDoesNotExist($target);
            }
        } finally { @unlink($source); @unlink($target); }
    }

    public function testRealHttpTransferIsBoundedWithoutTrustingContentLengthOrLeakingStorageUrls(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) self::markTestSkipped('Local HTTP fixture needs CLI processes and curl');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertNotFalse($socket); $address = stream_socket_get_name($socket, false); fclose($socket);
        $router = tempnam(sys_get_temp_dir(), 'voice_http_'); $target = tempnam(sys_get_temp_dir(), 'voice_http_target_');
        file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/missing') { http_response_code(404); echo 'missing'; return; }
if ($path === '/busy') { http_response_code(503); return; }
if ($path === '/redirect') { header('Location: /exact'); return; }
if ($path === '/redirect-file') { header('Location: file:///etc/passwd'); return; }
if ($path === '/short') { echo str_repeat('a', 31); return; }
if ($path === '/large') { for ($i = 0; $i < 10; $i++) { echo str_repeat('a', 10000); flush(); } return; }
echo str_repeat('a', 32);
PHP
        );
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        try {
            self::assertIsResource($process); fclose($pipes[0]); $ready = false;
            for ($i = 0; $i < 40; $i++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $message, 0.05);
                if ($connection) { fclose($connection); $ready = true; break; }
                usleep(50000);
            }
            self::assertTrue($ready, 'Local HTTP fixture must start');
            foreach (['exact', 'redirect'] as $route) {
                AudioMediaLoader::remote('http://' . $address . '/' . $route, $target, 32);
                self::assertSame(str_repeat('a', 32), file_get_contents($target));
            }
            foreach (['large' => 409, 'short' => 409, 'missing' => 404, 'busy' => 0, 'redirect-file' => 0] as $route => $code) {
                try { AudioMediaLoader::remote('http://' . $address . '/' . $route . '?private-token=secret', $target, 32); self::fail('Bad object accepted'); }
                catch (\RuntimeException $error) {
                    self::assertSame($code, $error->getCode()); self::assertStringNotContainsString('secret', $error->getMessage());
                    self::assertStringNotContainsString($address, $error->getMessage());
                }
                self::assertFileDoesNotExist($target);
            }
            $this->expectExceptionCode(409); AudioMediaLoader::remote('file://' . $router, $target, 32);
        } finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            @unlink($router); @unlink($target);
        }
    }
}
