<?php
namespace Tests\Feature;

use app\common\model\ai\AiTaskResultAsset;
use app\common\model\file\TenantFile;
use app\common\service\ai\AiTaskManagedFileService as Managed;
use app\common\service\app\aigc_image\AigcImageAssetService as Images;
use app\common\service\app\aigc_video\AigcVideoAssetService as Videos;
use app\common\service\app\aigc_music\AigcMusicAssetService as Audio;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class MarketTransferCheckpointTest extends TestCase
{
    private const TENANT = 199999961;
    private const USER = 199999962;
    private const CONFIG = ['scope' => 'tenant', 'default' => 'local', 'engine' => ['local' => []]];

    private function local(callable $check): void
    {
        if (getenv('AIGC_TRANSFER_CHECKPOINT_DB_TESTS') !== '1') self::markTestSkipped('Explicit local transfer checkpoint fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        $root = new \ReflectionProperty(\think\App::class, 'rootPath'); $root->setAccessible(true); $old = $root->getValue(app());
        $dir = sys_get_temp_dir() . '/b52-storage-' . bin2hex(random_bytes(8)); mkdir($dir, 0700); mkdir($dir . '/public', 0700); $root->setValue(app(), $dir . '/');
        Db::startTrans();
        try { $check($dir); }
        finally {
            Db::rollback(); $root->setValue(app(), $old);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($dir);
        }
    }

    private function asset(string $type): AiTaskResultAsset
    {
        return AiTaskResultAsset::create(['tenant_id' => self::TENANT, 'user_id' => self::USER, 'asset_type' => $type,
            'external_url' => 'data:' . $type . '/fixture', 'transfer_status' => 'external', 'storage_meta' => [], 'create_time' => time(), 'update_time' => time()]);
    }

    private function media(string $type): array
    {
        if ($type === 'image') return ['png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=')];
        $ext = $type === 'video' ? 'mp4' : 'wav'; $path = getenv('AIGC_TRANSFER_MEDIA_DIR') . '/synthetic.' . $ext;
        self::assertFileExists($path, 'Generate the isolated ffmpeg color/silence fixtures first');
        return [$ext, file_get_contents($path)];
    }

    private function persist(AiTaskResultAsset $asset, string $type, string $ext, string $bytes): array
    {
        $uri = 'data:' . $type . '/' . $ext . ';base64,' . base64_encode($bytes);
        return match ($type) {
            'image' => Images::persistGeneratedImage($uri, self::TENANT, self::USER, true, 0, self::CONFIG, (int)$asset['id']),
            'video' => Videos::persistGeneratedVideo($uri, self::TENANT, self::USER, self::CONFIG, (int)$asset['id']),
            'audio' => Audio::persistGeneratedAudio($uri, self::TENANT, self::USER, self::CONFIG, (int)$asset['id']),
        };
    }

    /** @dataProvider interruptions */
    public function testLocalBytesRecoverBeforeOrAfterTheDurableFileReceipt(string $type, bool $beforeReceipt): void
    {
        $this->local(function ($dir) use ($type, $beforeReceipt) {
            $asset = $this->asset($type); [$ext, $bytes] = $this->media($type);
            $interrupt = $beforeReceipt;
            Db::event('after_insert', function ($query) use (&$interrupt) {
                if ($interrupt && str_ends_with($query->getTable(), 'tenant_file') && (int)($query->getOptions('data')['source_id'] ?? 0) === self::USER) throw new \RuntimeException('fixture interruption after upload');
            });
            $error = null;
            try { $this->persist($asset, $type, $ext, $bytes); } catch (\RuntimeException $e) { $error = $e; }
            $interrupt = false;
            if ($beforeReceipt) { self::assertNotNull($error); self::assertSame('fixture interruption after upload', $error->getMessage()); }
            else self::assertNull($error);
            $fresh = AiTaskResultAsset::find($asset['id']); $checkpoint = $fresh['storage_meta']['transfer_checkpoint'];
            self::assertSame('external', $fresh['transfer_status']); self::assertSame(!$beforeReceipt, isset($checkpoint['receipt']));
            $path = $dir . '/public/' . $checkpoint['uri']; self::assertSame(hash('sha256', $bytes), hash_file('sha256', $path));
            self::assertSame($beforeReceipt ? 0 : 1, (int)TenantFile::where('tenant_id', self::TENANT)->count());
            touch($path, time() - 30); clearstatcache(true, $path); $mtime = filemtime($path);
            $receipt = Managed::recover($fresh, self::CONFIG); self::assertTrue($receipt['stored']); self::assertSame($checkpoint['uri'], $receipt['uri']);
            clearstatcache(true, $path); self::assertSame($mtime, filemtime($path)); self::assertSame(1, (int)TenantFile::where('tenant_id', self::TENANT)->count());
            self::assertSame($receipt, Managed::recover(AiTaskResultAsset::find($asset['id']), self::CONFIG));
            if ($type === 'audio') self::assertGreaterThan(0, $receipt['duration']);
        });
    }

    public static function interruptions(): array
    {
        $rows = []; foreach (['image', 'video', 'audio'] as $type) foreach ([true, false] as $before) $rows[] = [$type, $before]; return $rows;
    }

    /** @dataProvider types */
    public function testDeclaredMediaTypeCannotStoreNonMediaBytes(string $type, string $ext): void
    {
        $this->local(function ($dir) use ($type, $ext) {
            $asset = $this->asset($type); $error = null;
            try { $this->persist($asset, $type, $ext, 'not media bytes'); } catch (\Exception $e) { $error = $e; }
            self::assertNotNull($error); self::assertSame([], glob($dir . '/public/*')); self::assertSame(0, (int)TenantFile::where('tenant_id', self::TENANT)->count());
        });
    }
    public static function types(): array { return [['image', 'png'], ['video', 'mp4'], ['audio', 'wav']]; }

    /** @dataProvider formats */
    public function testActualSupportedMediaContainersKeepTheirBytes(string $type, string $ext): void
    {
        $this->local(function ($dir) use ($type, $ext) {
            $path = getenv('AIGC_TRANSFER_MEDIA_DIR') . '/synthetic.' . $ext; self::assertFileExists($path);
            $bytes = file_get_contents($path); $asset = $this->asset($type); $receipt = $this->persist($asset, $type, $ext, $bytes);
            self::assertTrue($receipt['stored']); self::assertStringEndsWith('.' . $ext, $receipt['uri']);
            $mime=['mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','m4v'=>'video/mp4','mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','flac'=>'audio/flac','aac'=>'audio/aac','m4a'=>'audio/mp4','opus'=>'audio/ogg'];
            self::assertSame($mime[$ext], $receipt['mime_type']);
            self::assertSame(hash('sha256', $bytes), hash_file('sha256', $dir . '/public/' . $receipt['uri']));
            self::assertSame(1, (int)TenantFile::where(['tenant_id' => self::TENANT, 'source_id' => self::USER])->count());
        });
    }
    public static function formats(): array
    {
        $rows = []; foreach (['mp4', 'webm', 'mov', 'm4v'] as $ext) $rows[] = ['video', $ext];
        foreach (['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'] as $ext) $rows[] = ['audio', $ext]; return $rows;
    }

    public function testADeclaredMimeCannotOverrideTheVerifiedAudioContainer(): void
    {
        $this->local(function ($dir) {
            $asset=$this->asset('audio');[$ext,$bytes]=$this->media('audio');$path=$dir.'/actual.wav';file_put_contents($path,$bytes);
            $receipt=Managed::persist($path,(int)$asset['id'],self::TENANT,self::USER,self::CONFIG,['mime_type'=>'video/mp4','duration'=>1]);
            self::assertSame('audio/wav',$receipt['mime_type']);self::assertSame(strlen($bytes),$receipt['file_size']);self::assertTrue($receipt['stored']);
        });
    }

    /** @dataProvider fences */
    public function testAChangedSourceStorageOrOwnerCannotReuseTheReceipt(string $change): void
    {
        $this->local(function ($dir) use ($change) {
            $asset = $this->asset('image'); [$ext, $bytes] = $this->media('image'); $receipt = $this->persist($asset, 'image', $ext, $bytes);
            $fresh = AiTaskResultAsset::find($asset['id']); $config = self::CONFIG; $error = null;
            if ($change === 'source') { $fresh->save(['external_url' => 'data:image/changed']); }
            if ($change === 'storage') $config['scope'] = 'platform';
            if ($change === 'file_owner') TenantFile::where('id', $receipt['file_id'])->update(['source_id' => self::USER + 1]);
            try {
                if ($change === 'asset_owner' || $change === 'content') {
                    $path = $dir . '/copy.png'; file_put_contents($path, $bytes . ($change === 'content' ? 'changed' : ''));
                    Managed::persist($path, (int)$asset['id'], self::TENANT, self::USER + ($change === 'asset_owner' ? 1 : 0), self::CONFIG, []);
                } else Managed::recover($fresh, $config);
            } catch (\RuntimeException $e) { $error = $e; }
            self::assertNotNull($error); self::assertSame(1, (int)TenantFile::where('tenant_id', self::TENANT)->count());
        });
    }
    public static function fences(): array { return [['source'], ['storage'], ['file_owner'], ['asset_owner'], ['content']]; }

    public function testPartialLocalBytesAreNotAValidRecoveryReceipt(): void
    {
        $this->local(function ($dir) {
            $asset = $this->asset('image'); [$ext, $bytes] = $this->media('image'); $receipt = $this->persist($asset, 'image', $ext, $bytes);
            file_put_contents($dir . '/public/' . $receipt['uri'], 'partial');
            self::assertNull(Managed::recover(AiTaskResultAsset::find($asset['id']), self::CONFIG));
            $again = $this->persist($asset, 'image', $ext, $bytes); self::assertSame($receipt['uri'], $again['uri']);
            self::assertSame(hash('sha256', $bytes), hash_file('sha256', $dir . '/public/' . $again['uri'])); self::assertSame(1, (int)TenantFile::where('tenant_id', self::TENANT)->count());
        });
    }
}
