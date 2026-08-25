<?php

namespace Tests\Feature;

use app\common\service\power\MarketApplicationApiRuntimeService;
use PHPUnit\Framework\TestCase;

class WatermarkRemovalAppContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testManifestIdentifiesThePaidShortVideoApp(): void
    {
        $manifest = $this->json('app/apps/aigc_watermark_removal/manifest.json');

        self::assertSame('aigc_watermark_removal', $manifest['code']);
        self::assertSame('短视频去水印', $manifest['name']);
        self::assertSame('1.0.1', $manifest['version']);
        self::assertSame(0, $manifest['is_builtin']);
        self::assertSame(['tenant', 'pc'], $manifest['frontends']);
        self::assertStringNotContainsString('/api/', (string)$manifest['description']);
        self::assertStringNotContainsString('/api/', (string)$manifest['changelog']);
    }

    public function testApiSchemaMenusAndPermissionsStayInTheAppNamespace(): void
    {
        $schema = $this->json('app/apps/aigc_watermark_removal/api_schema.json');
        $menus = $this->json('app/apps/aigc_watermark_removal/menus/tenant.json');
        $permissions = $this->json('app/apps/aigc_watermark_removal/permissions/tenant.json');

        self::assertSame('aigc_watermark_removal', $schema['app_code']);
        self::assertNotEmpty($schema['apis']);
        foreach ($schema['apis'] as $api) {
            self::assertStringStartsWith('app.aigc_watermark_removal.', $api['api_path']);
            self::assertStringStartsWith('aigc_watermark_removal:', $api['permission_key']);
        }
        self::assertSame('aigc_watermark_removal', $menus[0]['app_code']);
        self::assertSame('app', $menus[0]['source']);
        foreach ($permissions as $permission) {
            self::assertStringStartsWith('aigc_watermark_removal:', $permission['permission_key']);
            self::assertStringStartsWith('app.aigc_watermark_removal.', $permission['api_path']);
        }
    }

    public function testInstallAndUpgradeSurfacesCreateTheSameBusinessTables(): void
    {
        $install = file_get_contents($this->root . '/app/apps/aigc_watermark_removal/migrations/install.sql');
        $upgrade = file_get_contents($this->root . '/upgrade/20260822_aigc_watermark_removal.sql');
        $publicUpgrade = file_get_contents($this->root . '/public/upgrade/20260822_aigc_watermark_removal.sql');

        foreach (['la_aigc_watermark_removal_config', 'la_aigc_watermark_removal_task', 'la_aigc_watermark_removal_result'] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $install);
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $upgrade);
        }
        self::assertSame($upgrade, $publicUpgrade);
        self::assertStringNotContainsString('INSERT INTO `la_tenant_app`', $upgrade);
        self::assertStringNotContainsString('INSERT INTO `la_tenant_system_menu`', $upgrade);
    }

    public function testRuntimeUsesTheWatermarkRemovalApplicationApi(): void
    {
        $runtime = file_get_contents($this->root . '/app/common/service/power/MarketGenericImageAppRuntimeService.php');
        $service = file_get_contents($this->root . '/app/common/service/app/aigc_watermark_removal/AigcWatermarkRemovalService.php');

        self::assertStringContainsString("UPSTREAM_APP_CODE = 'watermark_removal'", $runtime);
        self::assertStringContainsString("UPSTREAM_API_CODE = 'remove'", $runtime);
        self::assertStringContainsString("'/api/v1/apps/'", $runtime);
        self::assertStringContainsString("return ['url' => " . '$url' . "]", $runtime);
        self::assertStringContainsString("AppAccessService::assertTenantCanUse", $service);
        self::assertStringContainsString('MarketGenericImageAppRuntimeService::reserve', $service);
        self::assertStringContainsString('MarketGenericImageAppRuntimeService::submit', $service);
    }

    public function testRuntimeAdapterIsNotConfusedWithOtherApplicationAdapters(): void
    {
        self::assertSame(
            'app\\common\\service\\power\\MarketGenericImageAppRuntimeService',
            MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'watermark_removal'])
        );
        self::assertNotSame(
            MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'music_generation']),
            MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'watermark_removal'])
        );
    }

    public function testFrontendPackageDocumentsBothDeclaredEntries(): void
    {
        $frontend = file_get_contents($this->root . '/app/apps/aigc_watermark_removal/frontend/README.md');

        self::assertStringContainsString('/ai/tools/aigc_watermark_removal', $frontend);
        self::assertStringContainsString('/app/aigc_watermark_removal', $frontend);
        self::assertStringContainsString('generate/index', $frontend);
        self::assertStringContainsString('task/lists', $frontend);
    }

    public function testFrontendRuntimeAssetsArePackagedAndLoadedByBothShells(): void
    {
        $manifest = $this->json('app/apps/aigc_watermark_removal/manifest.json');
        self::assertCount(5, $manifest['public_assets'] ?? []);
        foreach ($manifest['public_assets'] as $asset) {
            self::assertFileExists($this->root . '/app/apps/aigc_watermark_removal/' . $asset['source']);
            self::assertFileExists($this->root . '/' . $asset['target']);
        }

        $pcShell = file_get_contents($this->root . '/public/pc/index.html');
        $adminShell = file_get_contents($this->root . '/public/admin/index.html');
        self::assertStringContainsString('/watermark-removal-page.js', $pcShell);
        self::assertStringContainsString('/watermark-removal-admin.js', $adminShell);
        self::assertFileExists($this->root . '/public/pc/ai/tools/aigc_watermark_removal/index.html');
        self::assertStringContainsString('app.aigc_watermark_removal.generate/estimate', file_get_contents($this->root . '/public/watermark-removal-page.js'));
        self::assertStringContainsString('app.aigc_watermark_removal.config/setup', file_get_contents($this->root . '/public/watermark-removal-admin.js'));
        self::assertStringContainsString("fetch('/tenantapi/'", file_get_contents($this->root . '/public/watermark-removal-admin.js'));
    }

    public function testAppInstallerDeploysDeclaredRuntimeAssets(): void
    {
        $registry = file_get_contents($this->root . '/app/common/service/app/AppRegistryService.php');
        $updater = file_get_contents($this->root . '/app/common/service/update/AppPackageUpdateService.php');
        self::assertStringContainsString('self::installPublicAssets($manifest, root_path() . \'app/apps/\' . $appCode)', $registry);
        self::assertStringContainsString('AppRegistryService::installPublicAssets($manifest, $extractPath)', $updater);
    }

    private function json(string $relativePath): array
    {
        $data = json_decode((string)file_get_contents($this->root . '/' . $relativePath), true);
        self::assertIsArray($data, $relativePath . ' must contain valid JSON');
        return $data;
    }
}
