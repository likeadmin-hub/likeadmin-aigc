<?php

use app\common\service\app\AppRegistryService;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ImmutableAppAssetsTest extends TestCase
{
    private string $root;
    private array $manifest;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/immutable-app-assets-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->root . 'public/admin', 0777, true);
        mkdir($this->root . 'package', 0777, true);
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        new \think\App($this->root);
        $this->manifest = ['version' => '1.2.3', 'public_assets' => [['source' => 'bridge.js', 'target' => 'public/example-admin.js']]];
        file_put_contents($this->root . 'package/bridge.js', 'package bridge');
        file_put_contents($this->root . 'public/example-admin.js', 'deployed bridge');
        file_put_contents($this->root . 'public/admin/index.html', '<body><script defer src="/example-admin.js?v=prebuilt"></script></body>');
        putenv('LIKEADMIN_IMMUTABLE_ASSETS=1');
    }

    protected function tearDown(): void
    {
        putenv('LIKEADMIN_IMMUTABLE_ASSETS');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            chmod($item->getPathname(), 0777);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPrebuiltAssetsAndShellRemainUntouched(): void
    {
        chmod($this->root . 'public/example-admin.js', 0444);
        chmod($this->root . 'public/admin/index.html', 0444);
        AppRegistryService::installPublicAssets($this->manifest, $this->root . 'package');
        self::assertSame('deployed bridge', file_get_contents($this->root . 'public/example-admin.js'));
        self::assertStringContainsString('?v=prebuilt', file_get_contents($this->root . 'public/admin/index.html'));
    }

    public function testMissingAssetFailsWithRebuildInstruction(): void
    {
        unlink($this->root . 'public/example-admin.js');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('重建镜像');
        AppRegistryService::installPublicAssets($this->manifest, $this->root . 'package');
    }

    public function testMissingShellRegistrationFailsWithRebuildInstruction(): void
    {
        file_put_contents($this->root . 'public/admin/index.html', '<body></body>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('缺少应用前端入口');
        AppRegistryService::installPublicAssets($this->manifest, $this->root . 'package');
    }

    public function testWritableInstallationStillUpdatesAssetsAndScriptVersion(): void
    {
        putenv('LIKEADMIN_IMMUTABLE_ASSETS');
        AppRegistryService::installPublicAssets($this->manifest, $this->root . 'package');
        self::assertSame('package bridge', file_get_contents($this->root . 'public/example-admin.js'));
        self::assertStringContainsString('?v=1.2.3', file_get_contents($this->root . 'public/admin/index.html'));
    }

    public function testImagePreparationPreservesCompiledAssetsAndRegistersShell(): void
    {
        putenv('LIKEADMIN_IMMUTABLE_ASSETS');
        file_put_contents($this->root . 'public/admin/index.html', '<body></body>');
        AppRegistryService::installPublicAssets($this->manifest, $this->root . 'package', true);
        self::assertSame('deployed bridge', file_get_contents($this->root . 'public/example-admin.js'));
        self::assertStringContainsString('/example-admin.js?v=1.2.3', file_get_contents($this->root . 'public/admin/index.html'));
    }
}
