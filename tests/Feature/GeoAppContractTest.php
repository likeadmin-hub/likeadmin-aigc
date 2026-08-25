<?php

namespace Tests\Feature;

use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use app\common\service\power\MarketGeoAppRuntimeService;
use PHPUnit\Framework\TestCase;

class GeoAppContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function testPackageContainsInstallableApplicationContract(): void
    {
        $base = $this->root . '/app/apps/aigc_geo';
        foreach (['manifest.json', 'api_schema.json', 'signature.json', 'menus/platform.json', 'menus/tenant.json', 'permissions/tenant.json', 'migrations/install.sql', 'frontend/README.md'] as $file) {
            self::assertFileExists($base . '/' . $file);
        }

        $manifest = json_decode((string)file_get_contents($base . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('aigc_geo', $manifest['code']);
        self::assertSame('/app/aigc_geo', $manifest['api_prefix']);
        self::assertSame(['tenant', 'pc'], $manifest['frontends']);
        self::assertSame('aigc_geo', $manifest['market_profile']['upstream_app_code']);
        self::assertSame(['question_generate', 'content_generate', 'brand_diagnosis', 'content_publish'], $manifest['market_profile']['api_codes']);
    }

    public function testSignatureCoversAllPackagePayloadFiles(): void
    {
        $base = $this->root . '/app/apps/aigc_geo';
        $signature = json_decode((string)file_get_contents($base . '/signature.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($signature['sha256'] as $relative => $hash) {
            self::assertFileExists($base . '/' . $relative);
            self::assertSame($hash, hash_file('sha256', $base . '/' . $relative), $relative);
        }
    }

    public function testBusinessApiAndStorageSurfacesAreComplete(): void
    {
        $schema = (string)file_get_contents($this->root . '/app/apps/aigc_geo/api_schema.json');
        foreach (['config/detail', 'config/setup', 'console/summary', 'keyword/generate', 'article/generate', 'article/publish', 'diagnosis/run', 'task/lists'] as $path) {
            self::assertStringContainsString('app.aigc_geo.' . $path, $schema);
        }

        $sql = (string)file_get_contents($this->root . '/app/apps/aigc_geo/migrations/install.sql');
        foreach (['aigc_geo_config', 'aigc_geo_keyword', 'aigc_geo_article', 'aigc_geo_diagnosis', 'aigc_geo_task'] as $table) {
            self::assertStringContainsString('`la_' . $table . '`', $sql);
        }

        foreach (['Config', 'Console', 'Keyword', 'Article', 'Diagnosis', 'Task'] as $controller) {
            self::assertFileExists($this->root . '/app/tenantapi/controller/app/aigc_geo/' . $controller . 'Controller.php');
        }
        foreach (['Config', 'Console', 'Keyword', 'Article', 'Diagnosis', 'Task'] as $controller) {
            self::assertFileExists($this->root . '/app/api/controller/app/aigc_geo/' . $controller . 'Controller.php');
        }
    }

    public function testCurrentGeoFrontendIsPreserved(): void
    {
        $base = $this->root . '/public/pc/app/aigc_geo';
        self::assertFileExists($base . '/index.html');
        foreach (['brand', 'keywords', 'creation', 'site-articles', 'diagnosis'] as $route) {
            $page = $base . '/console/' . $route . '/index.html';
            self::assertFileExists($page);
            self::assertStringContainsString('/pc/app/aigc_geo/geo-enhancements.js', (string)file_get_contents($page));
        }
        self::assertFileExists($base . '/geo-enhancements.js');
        self::assertFileExists($this->root . '/public/_nuxt/geo-enhancements.css');
    }

    public function testPowerMarketAdapterIsExplicitlyRegistered(): void
    {
        self::assertSame(MarketGeoAppRuntimeService::class, MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code' => 'aigc_geo']));
        $profile = AppDisplayConfigService::marketProfile('aigc_geo');
        self::assertSame('app_api', $profile['resource_type']);
        self::assertSame('aigc_geo', $profile['upstream_app_code']);
        self::assertTrue((bool)$profile['requires_provider_configuration']);
    }
}
