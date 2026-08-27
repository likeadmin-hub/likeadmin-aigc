<?php

namespace Tests\Feature;

use app\common\service\power\MarketApplicationApiRuntimeService;
use PHPUnit\Framework\TestCase;

class MusicCoverAppContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testManifestDeclaresACompletePaidApplication(): void
    {
        $manifest = $this->json('app/apps/aigc_music_cover/manifest.json');

        self::assertSame('aigc_music_cover', $manifest['code']);
        self::assertSame('音乐翻唱', $manifest['name']);
        self::assertSame('1.0.3', $manifest['version']);
        self::assertSame('>=1.0.0 <2.0.0', $manifest['require_core']);
        self::assertSame(0, $manifest['is_builtin']);
        self::assertSame(['tenant', 'pc'], $manifest['frontends']);
        self::assertStringNotContainsString('/api/', $manifest['description']);
        self::assertFileExists($this->root . '/app/apps/aigc_music_cover/menus/platform.json');
        self::assertFileExists($this->root . '/app/apps/aigc_music_cover/signature.json');
    }

    public function testSchemaMenusAndPermissionsStayInTheApplicationNamespace(): void
    {
        $schema = $this->json('app/apps/aigc_music_cover/api_schema.json');
        $menus = $this->json('app/apps/aigc_music_cover/menus/tenant.json');
        $permissions = $this->json('app/apps/aigc_music_cover/permissions/tenant.json');

        self::assertSame('aigc_music_cover', $schema['app_code']);
        self::assertNotEmpty($schema['apis']);
        foreach ($schema['apis'] as $api) {
            self::assertStringStartsWith('app.aigc_music_cover.', $api['api_path']);
            self::assertStringStartsWith('aigc_music_cover:', $api['permission_key']);
        }
        self::assertSame('aigc_music_cover', $menus[0]['app_code']);
        foreach ($permissions as $permission) {
            self::assertStringStartsWith('aigc_music_cover:', $permission['permission_key']);
            self::assertStringStartsWith('app.aigc_music_cover.', $permission['api_path']);
        }
    }

    public function testInstallAndSystemUpgradeCreateTheCompleteDualAudioSchema(): void
    {
        $install = file_get_contents($this->root . '/app/apps/aigc_music_cover/migrations/install.sql');
        $upgrade = file_get_contents($this->root . '/upgrade/20260822_aigc_music_cover.sql');
        $publicUpgrade = file_get_contents($this->root . '/public/upgrade/20260822_aigc_music_cover.sql');

        foreach (['la_aigc_music_asset', 'la_aigc_music_cover_config', 'la_aigc_music_cover_task', 'la_aigc_music_cover_result'] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $install);
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $upgrade);
        }
        self::assertFileExists($this->root . '/app/apps/aigc_music_cover/migrations/upgrade_20260825_release.sql');
        $planMigration = file_get_contents($this->root . '/app/apps/aigc_music_cover/migrations/upgrade_20260826_default_plan.sql');
        self::assertStringContainsString("'aigc_music_cover','一年套餐',12", $planMigration);
        self::assertStringContainsString('WHERE NOT EXISTS', $planMigration);
        foreach (['reference_asset_id', 'reference_uri', 'reference_url'] as $column) {
            self::assertStringContainsString('`' . $column . '`', $install);
            self::assertStringContainsString($column, $upgrade);
        }
        self::assertSame($upgrade, $publicUpgrade);
        self::assertStringNotContainsString('INSERT INTO `la_tenant_app`', $upgrade);
        self::assertStringNotContainsString('INSERT INTO `la_tenant_system_menu`', $upgrade);
    }

    public function testRuntimeTargetsTheSeedSvcApplicationApi(): void
    {
        $runtime = file_get_contents($this->root . '/app/common/service/power/MarketSeedSvcAppRuntimeService.php');
        $service = file_get_contents($this->root . '/app/common/service/app/aigc_music_cover/AigcMusicCoverService.php');

        self::assertStringContainsString("UPSTREAM_APP_CODE = 'seedsvc'", $runtime);
        self::assertStringContainsString("SUBMIT_API_CODE = 'submit'", $runtime);
        self::assertStringContainsString("'ref_audio'", $runtime);
        self::assertStringContainsString("'source_audio'", $runtime);
        self::assertStringContainsString('AppAccessService::assertTenantCanUse', $service);
        self::assertStringContainsString('MarketApplicationApiRuntimeService::reserve', $service);
        self::assertStringContainsString("\$query->where('user_id', \$userId)", $service);
        self::assertStringContainsString(
            "retryTask((int)\$this->request->tenantId, (int)\$this->request->post('id', 0), \$this->userId)",
            file_get_contents($this->root . '/app/api/controller/app/aigc_music_cover/TaskController.php')
        );
        self::assertSame(
            'app\\common\\service\\power\\MarketSeedSvcAppRuntimeService',
            MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'seedsvc'])
        );
    }

    public function testFrontendAssetsCoverUploadGenerationPollingAndAdministration(): void
    {
        $manifest = $this->json('app/apps/aigc_music_cover/manifest.json');
        self::assertCount(5, $manifest['public_assets'] ?? []);
        foreach ($manifest['public_assets'] as $asset) {
            self::assertFileExists($this->root . '/app/apps/aigc_music_cover/' . $asset['source']);
            self::assertFileExists($this->root . '/' . $asset['target']);
            self::assertSame(
                hash_file('sha256', $this->root . '/app/apps/aigc_music_cover/' . $asset['source']),
                hash_file('sha256', $this->root . '/' . $asset['target'])
            );
        }

        $pc = file_get_contents($this->root . '/public/music-cover-page.js');
        $tenant = file_get_contents($this->root . '/public/music-cover-admin.js');
        self::assertStringContainsString('new FormData()', $pc);
        self::assertStringContainsString('app.aigc_music_cover.asset/upload_audio', $pc);
        self::assertStringContainsString('source_asset_id', $pc);
        self::assertStringContainsString('reference_asset_id', $pc);
        self::assertStringContainsString('app.aigc_music_cover.generate/index', $pc);
        self::assertStringContainsString('app.aigc_music_cover.task/lists', $pc);
        self::assertStringContainsString("fetch('/tenantapi/'", $tenant);
        self::assertStringContainsString('aigc-music-cover', $tenant);
        self::assertStringContainsString("addEventListener('hashchange', check)", $tenant);
        self::assertStringContainsString('app.aigc_music_cover.config/setup', $tenant);
        self::assertStringContainsString('app.aigc_music_cover.task/retry', $tenant);
    }

    public function testBothShellsLoadTheRuntimeAndStaticPcRouteExists(): void
    {
        self::assertStringContainsString('/music-cover-page.js', file_get_contents($this->root . '/public/pc/index.html'));
        self::assertStringContainsString('/music-cover-admin.js', file_get_contents($this->root . '/public/admin/index.html'));
        self::assertFileExists($this->root . '/public/pc/ai/tools/aigc_music_cover/index.html');
    }

    public function testSignatureManifestCoversEveryPackageFile(): void
    {
        $signature = $this->json('app/apps/aigc_music_cover/signature.json');
        $root = $this->root . '/app/apps/aigc_music_cover';
        $entries = $signature['sha256'] ?? [];
        self::assertNotEmpty($entries);
        foreach ($entries as $relative => $hash) {
            $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            self::assertFileExists($path, $relative);
            self::assertSame(strtolower((string)$hash), strtolower((string)hash_file('sha256', $path)), $relative);
        }
    }

    private function json(string $relativePath): array
    {
        $data = json_decode((string)file_get_contents($this->root . '/' . $relativePath), true);
        self::assertIsArray($data, $relativePath . ' must contain valid JSON');
        return $data;
    }
}
