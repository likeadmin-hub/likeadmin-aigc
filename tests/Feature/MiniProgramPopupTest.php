<?php
use app\common\service\wechat\MiniProgramPopupService;
use app\common\service\wechat\OpenPlatformService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/** @runTestsInSeparateProcesses @preserveGlobalState disabled */
final class MiniProgramPopupTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'popup_test', 'connections' => ['popup_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        Db::execute('CREATE TABLE la_wechat_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, settings_json TEXT, create_time INTEGER, update_time INTEGER)');
    }

    public function testTenantSaveReadClearAndUnrelatedSaves(): void
    {
        OpenPlatformService::saveCredentials(1, ['settings_json' => ['qr_popup' => ['title' => ' 手机创作 ', 'title_en' => 'Mobile', 'secret' => 'not-public']]]);
        OpenPlatformService::saveCredentials(2, ['settings_json' => ['qr_popup' => ['title' => '另一租户']]]);
        $this->assertSame('手机创作', MiniProgramPopupService::forTenant(1)['title']);
        $this->assertSame('Mobile', MiniProgramPopupService::forTenant(1)['title_en']);
        $this->assertSame('另一租户', MiniProgramPopupService::forTenant(2)['title']);
        $this->assertCount(6, MiniProgramPopupService::forTenant(1));
        $this->assertSame('', MiniProgramPopupService::forTenant(3)['title']);
        OpenPlatformService::saveCredentials(1, ['settings_json' => ['service_path' => '/help']]);
        $this->assertSame('手机创作', MiniProgramPopupService::forTenant(1)['title']);
        OpenPlatformService::saveCredentials(1, ['settings_json' => ['qr_popup' => []]]);
        $this->assertSame('', MiniProgramPopupService::forTenant(1)['title']);
    }

    public function testOversizedCopyIsRejectedBeforeWriting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenPlatformService::saveCredentials(1, ['settings_json' => ['qr_popup' => ['title' => str_repeat('字', 61)]]]);
    }
}
