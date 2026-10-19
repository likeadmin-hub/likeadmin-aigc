<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Qiniu\Auth;
use Qiniu\Config;
use Qiniu\Region;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

final class QiniuPhp82CompatibilityTest extends TestCase
{
    private $httpServer;
    private string $endpoint;
    private string $serverLog;
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (error_reporting() & $severity) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        if (is_resource($this->httpServer)) {
            proc_terminate($this->httpServer);
            proc_close($this->httpServer);
        }
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        restore_error_handler();
        parent::tearDown();
    }

    public function testUploadAndBucketClientsInitializeUnderFrameworkErrorHandling(): void
    {
        // ThinkPHP turns E_DEPRECATED into exceptions on PHP 8.2. The old
        // SDK creates Config::$zone dynamically before any HTTP request.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            self::assertInstanceOf(UploadManager::class, new UploadManager());
            self::assertInstanceOf(BucketManager::class, new BucketManager(new Auth('fixture-ak', 'fixture-sk')));
            self::assertInstanceOf(Config::class, new Config());
        } finally {
            restore_error_handler();
        }
    }

    public function testSmallFileUploadPreservesObjectKeyMimeAndBytes(): void
    {
        $config = $this->loopbackConfig();
        $path = $this->temporaryFile("fixture image\0bytes");
        [$result, $error] = (new UploadManager($config))->putFile(
            $this->token(), 'tenant/7/image.png', $path, null, 'image/png'
        );
        self::assertNull($error);
        self::assertSame('tenant/7/image.png', $result['key']);
        self::assertSame('image/png', $result['mime']);
        self::assertSame("fixture image\0bytes", base64_decode($result['body']));
    }

    public function testLargeFileUsesMultipleBlocksAndCompletesTheObject(): void
    {
        $config = $this->loopbackConfig();
        $path = $this->temporaryFile(str_repeat('a', 4194304) . 'b');
        [$result, $error] = (new UploadManager($config))->putFile(
            $this->token(), 'tenant/7/video.mp4', $path, null, 'video/mp4'
        );
        self::assertNull($error);
        self::assertSame('tenant/7/video.mp4', $result['key']);
        self::assertSame(4194305, $result['size']);
        self::assertSame('video/mp4', $result['mime']);
        self::assertSame('block-4194304,block-1', $result['contexts']);
    }

    public function testStorageErrorsRemainAvailableToTheUploadDriver(): void
    {
        $config = $this->loopbackConfig();
        [$result, $error] = (new UploadManager($config))->putFile(
            $this->token(), 'reject', $this->temporaryFile('rejected file')
        );
        self::assertNull($result);
        self::assertSame('fixture upload denied', $error->message());
    }

    public function testFetchAndDeleteKeepTheirExistingBucketManagerContract(): void
    {
        $manager = new BucketManager(new Auth('fixture-ak', 'fixture-sk'), $this->loopbackConfig());
        [$result, $error] = $manager->fetch('https://example.invalid/image.png', 'fixture-bucket', 'tenant/7/fetch.png');
        self::assertNull($error);
        self::assertSame('fixture-bucket:tenant/7/fetch.png', $result['entry']);
        [$result, $error] = $manager->delete('fixture-bucket', 'tenant/7/fetch.png');
        self::assertNull($error);
        self::assertSame('fixture-bucket:tenant/7/fetch.png', $result['entry']);
    }

    public function testPrivateDownloadUrlKeepsTheFiveMinuteSignatureContract(): void
    {
        $before = time();
        $url = (new Auth('fixture-ak', 'fixture-sk'))->privateDownloadUrl('https://cdn.example.invalid/tenant/7/image.png', 300);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertGreaterThanOrEqual($before + 300, (int)$query['e']);
        self::assertLessThanOrEqual(time() + 300, (int)$query['e']);
        $unsigned = 'https://cdn.example.invalid/tenant/7/image.png?e=' . $query['e'];
        $signature = strtr(base64_encode(hash_hmac('sha1', $unsigned, 'fixture-sk', true)), '+/', '-_');
        self::assertSame('fixture-ak:' . $signature, $query['token']);
    }

    private function token(): string
    {
        return (new Auth('fixture-ak', 'fixture-sk'))->uploadToken('fixture-bucket');
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qiniu-php82-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);
        return $path;
    }

    private function loopbackConfig(): Config
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $this->endpoint = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->serverLog = $this->temporaryFile('');
        $this->httpServer = proc_open(
            [PHP_BINARY, '-d', 'post_max_size=16M', '-d', 'upload_max_filesize=16M', '-S', $this->endpoint,
                dirname(__DIR__) . '/fixtures/qiniu-storage-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->serverLog, 'a'], 2 => ['file', $this->serverLog, 'a']],
            $pipes
        );
        self::assertIsResource($this->httpServer);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $ready = @stream_socket_client('tcp://' . $this->endpoint, $errno, $message, 0.05);
            if ($ready !== false) {
                fclose($ready);
                return new Config(new Region([$this->endpoint], [$this->endpoint], $this->endpoint,
                    $this->endpoint, $this->endpoint, $this->endpoint));
            }
            usleep(10000);
        }
        self::fail('Qiniu fixture did not start: ' . file_get_contents($this->serverLog));
    }
}
