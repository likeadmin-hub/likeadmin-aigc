<?php
declare(strict_types=1);

use app\common\enum\FileEnum;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class AigcVideoFilePersistenceTest extends TestCase
{
    private string $directory;
    private array $published = [];
    private const TENANT = 987654321;
    private const USER = 987654322;

    public static function setUpBeforeClass(): void
    {
        (new \think\App())->initialize();
        self::assertSame('127.0.0.1', config('database.connections.mysql.hostname'));
        self::assertSame('z_cn', config('database.connections.mysql.database'));
        request()->setHost('localhost');
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->directory = sys_get_temp_dir() . '/video-file-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        foreach ($this->published as $uri) @unlink(dirname(__DIR__, 2) . '/public/' . $uri);
        foreach (glob($this->directory . '/*') ?: [] as $path) @unlink($path);
        @rmdir($this->directory);
    }

    private function persist(string $path, int $tenant = self::TENANT): array
    {
        self::assertTrue(is_callable([AigcVideoAssetService::class, 'persistGeneratedVideoFile']), 'Generated files need a bounded-memory publication path');
        $result = AigcVideoAssetService::persistGeneratedVideoFile($path, $tenant, self::USER, ['default' => 'local', 'scope' => 'tenant']);
        $this->published[] = $result['uri'];
        return $result;
    }

    public function testLargeVideoPublishesWithBoundedMemoryAndOwnedStorageReceipt(): void
    {
        $binary = new ReflectionMethod(MicroVideoMedia::class, 'binary');
        $binary->setAccessible(true);
        $run = new ReflectionMethod(MicroVideoMedia::class, 'run');
        $run->setAccessible(true);
        $path = $this->directory . '/large.mp4';
        $run->invoke(null, [$binary->invoke(null, 'ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=160x90:r=30:d=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path], 60);
        // A valid, ignorable MP4 free box reproduces large output without a large PHP string.
        $originalSize = filesize($path);
        $file = fopen($path, 'ab');
        $padding = 32 * 1024 * 1024;
        fwrite($file, pack('N', $padding) . 'free');
        self::assertTrue(ftruncate($file, $originalSize + $padding));
        fclose($file);
        clearstatcache(true, $path);
        self::assertGreaterThan(32 * 1024 * 1024, filesize($path));
        $before = memory_get_usage(true);
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $result = $this->persist($path);
        self::assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'Publication must not duplicate the whole video or encode it as base64');
        self::assertTrue($result['stored']);
        self::assertSame('tenant', $result['storage_scope']);
        self::assertSame('local', $result['storage_engine']);
        self::assertSame('http://localhost', $result['storage_domain']);
        self::assertFileExists($path, 'The caller still owns its working file');
        self::assertSame(hash_file('sha256', $path), hash_file('sha256', dirname(__DIR__, 2) . '/public/' . $result['uri']));
        self::assertSame(1, Db::name('tenant_file')->where(['tenant_id' => self::TENANT, 'source_id' => self::USER, 'source' => FileEnum::SOURCE_USER, 'type' => FileEnum::VIDEO_TYPE, 'uri' => $result['uri'], 'storage_scope' => 'tenant', 'storage_engine' => 'local'])->count());
    }

    public function testInvalidFilesAndOwnershipNeverPublish(): void
    {
        self::assertTrue(is_callable([AigcVideoAssetService::class, 'persistGeneratedVideoFile']));
        $bad = $this->directory . '/bad.mp4';
        file_put_contents($bad, '<html>not a video</html>');
        $valid = $this->directory . '/valid.mp4';
        file_put_contents($valid, pack('N', 24) . 'ftypisom' . str_repeat("\0", 16));
        $large = $this->directory . '/oversized.mp4';
        $file = fopen($large, 'wb');
        fwrite($file, pack('N', 24) . 'ftypisom' . str_repeat("\0", 16));
        ftruncate($file, 268435457);
        fclose($file);
        foreach ([[$bad, self::TENANT], [$large, self::TENANT], [$valid, 0], [$this->directory . '/missing.mp4', self::TENANT], ['https://example.invalid/video.mp4', self::TENANT]] as [$path, $tenant]) {
            $failure = null;
            try {
                $this->persist($path, $tenant);
            } catch (\Exception $error) {
                $failure = $error;
            }
            self::assertInstanceOf(\Exception::class, $failure, 'Invalid publication input was accepted');
            self::assertNotSame('', $failure->getMessage());
        }
        self::assertSame([], $this->published);
    }
}
