<?php
declare(strict_types=1);

use app\common\enum\FileEnum;
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Run against the local development database; fixture rows are always rolled back. */
final class MicroVideoMediaTest extends TestCase
{
    private string $directory;
    private string $relative;
    private array $published = [];
    private const TENANT = 987654321;
    private const USER = 987654322;

    public static function setUpBeforeClass(): void
    {
        (new \think\App())->initialize();
        request()->setHost('localhost');
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->relative = 'uploads/micro-video-media-tests/' . bin2hex(random_bytes(8));
        $this->directory = dirname(__DIR__, 2) . '/public/' . $this->relative;
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        foreach ($this->published as $uri) {
            $path = dirname(__DIR__, 2) . '/public/' . $uri;
            if (is_file($path)) unlink($path);
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) if (is_file($path) || is_link($path)) unlink($path);
        if (is_dir($this->directory)) rmdir($this->directory);
        $parent = dirname($this->directory);
        if (is_dir($parent) && count(scandir($parent)) === 2) rmdir($parent);
    }

    private function invoke(string $method, array $arguments = [])
    {
        $reflection = new ReflectionMethod(MicroVideoMedia::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    private function image(string $name = 'owned.png', array $overrides = []): array
    {
        $uri = $this->relative . '/' . $name;
        file_put_contents(dirname(__DIR__, 2) . '/public/' . $uri, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII='));
        $row = array_merge(['tenant_id' => self::TENANT, 'source_id' => self::USER, 'source' => FileEnum::SOURCE_USER, 'type' => FileEnum::IMAGE_TYPE, 'cid' => 0, 'uri' => $uri, 'name' => $name, 'storage_scope' => 'platform', 'storage_engine' => 'local', 'storage_domain' => '', 'create_time' => time(), 'delete_time' => 0], $overrides);
        $row['id'] = Db::name('tenant_file')->insertGetId($row);
        return $row;
    }

    private function rejects(callable $operation): void
    {
        try { $operation(); } catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); return; }
        self::fail('An unauthorized or invalid media input was accepted');
    }

    public function testOwnedUploadAndGeneratedReceiptBecomeActualVisionBytes(): void
    {
        foreach (['upload.png', 'generated.png'] as $name) {
            $row = $this->image($name);
            $images = MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $row['uri'], 'storage_engine' => 'qcloud', 'storage_domain' => 'http://169.254.169.254', 'role' => '商品']], 4);
            self::assertSame('local', $images[0]['storage_engine']);
            self::assertSame((int)$row['id'], $images[0]['file_id']);
            self::assertSame('商品', $images[0]['role']);
            $input = MicroVideoMedia::visionInputs($images)[0];
            self::assertStringStartsWith('data:image/png;base64,', $input);
            self::assertSame(file_get_contents(dirname(__DIR__, 2) . '/public/' . $row['uri']), base64_decode(explode(',', $input, 2)[1], true));
        }
    }

    public function testOwnershipTenantUserDeletionTypeAndOriginAreEnforced(): void
    {
        $row = $this->image();
        foreach ([[self::TENANT + 1, self::USER], [self::TENANT, self::USER + 1]] as [$tenant, $user]) $this->rejects(fn() => MicroVideoMedia::validateImages($tenant, $user, [['uri' => $row['uri']]], 4));
        foreach (['http://169.254.169.254/', 'https://example.org/'] as $origin) $this->rejects(fn() => MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $origin . $row['uri']]], 4));
        $frozen = MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $row['uri']]], 4);
        Db::name('tenant_file')->where('id', $row['id'])->update(['delete_time' => time()]);
        $this->rejects(fn() => MicroVideoMedia::visionInputs($frozen));
        $other = $this->image('admin.png', ['source' => FileEnum::SOURCE_ADMIN]);
        $this->rejects(fn() => MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $other['uri']]], 4));
        $other = $this->image('video.png', ['type' => FileEnum::VIDEO_TYPE]);
        $this->rejects(fn() => MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $other['uri']]], 4));
    }

    public function testTraversalProtocolSymlinkAndMalformedRasterAreRejected(): void
    {
        foreach (['uploads/../.env', 'uploads/%2e%2e/.env', 'uploads/%252e%252e/.env', '//example.org/uploads/a.png', 'file:///etc/passwd', 'http://user:pass@example.org/uploads/a.png', "uploads/a\0.png", 'uploads\\a.png'] as $uri) $this->rejects(fn() => $this->invoke('canonicalUri', [$uri]));
        $row = $this->image('bad.png'); file_put_contents($this->directory . '/bad.png', '<svg/>');
        $this->rejects(fn() => MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $row['uri']]], 4));
        $row = $this->image('link.png'); unlink($this->directory . '/link.png'); symlink('/etc/passwd', $this->directory . '/link.png');
        $this->rejects(fn() => MicroVideoMedia::validateImages(self::TENANT, self::USER, [['uri' => $row['uri']]], 4));
        $this->rejects(fn() => MicroVideoMedia::validateImages(self::TENANT, self::USER, array_fill(0, 5, ['uri' => 'uploads/a.png']), 4));
    }

    public function testMixedAudioAndDifferentClipLengthsMergeInFullOrder(): void
    {
        $ffmpeg = $this->invoke('binary', ['ffmpeg']);
        $inputs = [];
        foreach ([['red', '320x180', '1.2', 440], ['blue', '160x90', '0.7', null], ['green', '320x240', '1.1', 880]] as $index => [$color, $size, $duration, $frequency]) {
            $path = $this->directory . '/clip-' . $index . '.mp4';
            $command = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=' . $color . ':s=' . $size . ':r=30:d=' . $duration];
            if ($frequency !== null) $command = array_merge($command, ['-f', 'lavfi', '-i', 'sine=frequency=' . $frequency . ':sample_rate=44100:duration=' . $duration, '-c:a', 'aac']);
            $command = array_merge($command, ['-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path]);
            $this->invoke('run', [$command, 60]); $inputs[] = $path;
        }
        $result = $this->invoke('concatLocal', [$inputs, $this->directory]);
        self::assertEqualsWithDelta(3.0, $result['duration'], 0.15);
        self::assertSame(320, $result['width']); self::assertSame(180, $result['height']);
        self::assertTrue($result['has_audio']); self::assertFileExists($result['path']); self::assertFileDoesNotExist($this->directory . '/merged.partial.mp4');
        foreach ([[0.4, 0], [1.5, 2], [2.4, 1]] as [$seek, $dominant]) {
            $rgb = $this->invoke('run', [[$ffmpeg, '-hide_banner', '-loglevel', 'error', '-ss', (string)$seek, '-i', $result['path'], '-vf', 'scale=1:1', '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1'], 30]);
            self::assertSame(3, strlen($rgb)); $values = array_values(unpack('C*', $rgb));
            self::assertGreaterThan($values[($dominant + 1) % 3] + 40, $values[$dominant]); self::assertGreaterThan($values[($dominant + 2) % 3] + 40, $values[$dominant]);
        }
        foreach ([[0.4, true], [1.45, false], [2.4, true]] as [$seek, $audible]) {
            $pcm = $this->invoke('run', [[$ffmpeg, '-hide_banner', '-loglevel', 'error', '-ss', (string)$seek, '-i', $result['path'], '-t', '0.15', '-map', '0:a:0', '-f', 'f32le', '-ac', '1', 'pipe:1'], 30]);
            $samples = unpack('g*', $pcm); self::assertNotEmpty($samples);
            $rms = sqrt(array_sum(array_map(static fn(float $sample): float => $sample * $sample, $samples)) / count($samples));
            if ($audible) self::assertGreaterThan(0.02, $rms, 'Original source audio must remain audible');
            else self::assertLessThan(0.001, $rms, 'Missing source audio must stay silent');
        }
    }

    public function testInvalidMediaNeverReachesPublication(): void
    {
        file_put_contents($this->directory . '/invalid.mp4', 'not an MP4');
        $this->rejects(fn() => $this->invoke('probe', [$this->directory . '/invalid.mp4']));
        self::assertFileDoesNotExist($this->directory . '/merged.mp4');
        $workspace = $this->invoke('workspace'); file_put_contents($workspace . '/partial.mp4', 'partial');
        $this->invoke('cleanup', [$workspace]); self::assertDirectoryDoesNotExist($workspace);
    }

    public function testDurableSegmentAndWholeVideoUseRealStorageReceipts(): void
    {
        if (\app\common\service\storage\StorageConfigService::getEffectiveDefault(self::TENANT) !== 'local') self::markTestSkipped('Durable fixture check requires existing local storage; never changes storage settings');
        $ffmpeg = $this->invoke('binary', ['ffmpeg']); $segments = [];
        foreach ([0.8, 0.6] as $index => $duration) {
            $source = $this->directory . '/durable-' . $index . '.mp4';
            $this->invoke('run', [[$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=160x90:r=30:d=' . $duration, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $source], 60]);
            $segment = MicroVideoMedia::persistSegment(self::TENANT, self::USER, 'data:video/mp4;base64,' . base64_encode(file_get_contents($source)), 41, $index + 1);
            $this->published[] = $segment['video_uri'];
            self::assertFileExists(dirname(__DIR__, 2) . '/public/' . $segment['video_uri']);
            self::assertSame(1, Db::name('tenant_file')->where(['tenant_id' => self::TENANT, 'source_id' => self::USER, 'uri' => $segment['video_uri'], 'type' => FileEnum::VIDEO_TYPE])->count());
            self::assertEqualsWithDelta($duration, $segment['duration'], 0.06);
            $segments[] = $segment + ['id' => $index + 1, 'task_id' => 41, 'status' => 'success', 'sort' => $index + 1];
        }
        $managed = MicroVideoMedia::persistSegment(self::TENANT, self::USER, $segments[0]['video_url'], 41, 9);
        $this->published[] = $managed['video_uri'];
        self::assertEqualsWithDelta($segments[0]['duration'], $managed['duration'], 0.01);
        $result = MicroVideoMedia::merge(['id' => 41, 'tenant_id' => self::TENANT, 'user_id' => self::USER], array_reverse($segments));
        $this->published[] = $result['uri']; $this->published[] = $result['cover_uri'];
        self::assertEqualsWithDelta(1.4, $result['duration'], 0.15);
        self::assertSame('local', $result['storage_engine']);
        self::assertSame(160, $result['width']); self::assertSame(90, $result['height']);
        self::assertFileExists(dirname(__DIR__, 2) . '/public/' . $result['uri']);
        self::assertNotFalse(getimagesize(dirname(__DIR__, 2) . '/public/' . $result['cover_uri']));
        self::assertSame(1, Db::name('tenant_file')->where(['tenant_id' => self::TENANT, 'source_id' => self::USER, 'uri' => $result['cover_uri'], 'type' => FileEnum::IMAGE_TYPE])->count());
    }
}
