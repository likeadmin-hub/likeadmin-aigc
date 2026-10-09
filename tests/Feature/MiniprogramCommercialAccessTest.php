<?php
namespace tests\Feature;

use app\common\service\license\MiniprogramAccessService;
use app\common\service\license\SiteLicenseAccessService;
use PHPUnit\Framework\TestCase;

class MiniprogramCommercialAccessTest extends TestCase
{
    public function testOnlyVerifiedCommercialAccessEnablesMiniprogram(): void
    {
        self::assertTrue(class_exists(MiniprogramAccessService::class), 'Mini-program commercial access policy is required');
        foreach ([
            [[], false],
            [['edition'=>'free', 'can_customize'=>true], false],
            [['edition'=>'commercial', 'can_customize'=>false], false],
            [['edition'=>'commercial', 'can_customize'=>true], true],
        ] as [$state, $expected]) {
            $license = new class($state) extends SiteLicenseAccessService {
                private array $state;
                public function __construct(array $state) { $this->state=$state; }
                public function snapshot(): array { return $this->state; }
            };
            $service = new MiniprogramAccessService($license);
            self::assertSame($expected, $service->enabled());
            if (!$expected) {
                try { $service->assertEnabled(); self::fail('Unlicensed access must fail'); }
                catch (\RuntimeException $e) { self::assertStringContainsString('商业授权专属', $e->getMessage()); }
            } else { $service->assertEnabled(); }
        }
    }

    public function testManagementCannotBypassLicenseThroughOldOrSharedEndpoints(): void
    {
        self::assertTrue(class_exists(MiniprogramAccessService::class), 'Mini-program commercial access policy is required');
        foreach ([
            ['tenant', 'channel.MnpSettings', 'getConfig', '', true],
            ['tenant', 'channel.mnp_settings', 'set_config', '', true],
            ['tenant', 'channel.OpenPlatform', 'miniprogramPrivacy', '', true],
            ['tenant', 'channel.open_platform', 'saveCredentials', '', true],
            ['tenant', 'channel.OpenPlatform', 'versions', '', true],
            ['tenant', 'channel.OpenPlatform', 'authUrl', 'miniprogram', true],
            ['tenant', 'channel.OpenPlatform', 'authUrl', '', true],
            ['tenant', 'channel.OpenPlatform', 'authUrl', 'official', false],
            ['tenant', 'channel.OpenPlatform', 'syncAccount', 'miniprogram', true],
            ['tenant', 'channel.OpenPlatform', 'unbind', 'official', false],
            ['tenant', 'channel.OpenPlatform', 'accounts', '', false],
            ['tenant', 'channel.OpenPlatform', 'status', '', false],
            ['tenant', 'channel.OfficialAccountSetting', 'setConfig', '', false],
            ['platform', 'OpenPlatform', 'uploadDraft', '', true],
            ['platform', 'open_platform', 'promoteArtifact', '', true],
            ['platform', 'OpenPlatform', 'saveConfig', '', false],
            ['platform', 'OpenPlatform', 'authUrl', '', true],
            ['platform', 'OpenPlatform', 'authUrl', 'official', false],
            ['platform', 'Upgrade', 'licenseInfo', '', false],
        ] as [$scope,$controller,$action,$type,$expected]) {
            self::assertSame($expected, MiniprogramAccessService::requiresAccess($scope,$controller,$action,$type), "$controller/$action ($type)");
        }
    }
    public function testMiddlewareDeniesBeforeControllerAndPreservesOfficialRequests(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        new \think\App();
        $license = new class extends SiteLicenseAccessService {
            public function snapshot(): array { return ['edition'=>'free', 'can_customize'=>false]; }
        };
        $middleware = new class(new MiniprogramAccessService($license)) extends \app\common\http\middleware\MiniprogramAccessMiddleware {
            protected function accountType($request): string { return 'miniprogram'; }
        };
        $request = new class {
            public string $source = \app\common\enum\AdminTerminalEnum::TENANT;
            public string $controllerName = 'channel.MnpSettings';
            public string $actionName = 'setConfig';
            public array $params = [];
            public array $adminInfo = ['root'=>1, 'tenant_id'=>7];
            public function controller() { return $this->controllerName; }
            public function action() { return $this->actionName; }
            public function param($key, $default = null) { return $this->params[$key] ?? $default; }
            public function post() { return $this->params; }
        };
        $calls = 0;
        $next = function () use (&$calls) { $calls++; return 'allowed'; };
        $response = $middleware->handle($request, $next);
        self::assertSame(0, $response->getData()['code']);
        self::assertSame('MINIPROGRAM_COMMERCIAL_REQUIRED', $response->getData()['data']['reason_code']);
        self::assertSame(0, $calls, 'Root admin must not bypass qualification');
        $request->controllerName = 'channel.OpenPlatform';
        $request->actionName = 'syncAccount';
        $request->params = ['id'=>5, 'authorizer_type'=>'official'];
        self::assertSame(0, $middleware->handle($request, $next)->getData()['code']);
        self::assertSame(0, $calls, 'Forged account type must not bypass qualification');
        $request->actionName = 'authUrl';
        self::assertSame('allowed', $middleware->handle($request, $next));
        self::assertSame(1, $calls);
        $request->source = \app\common\enum\AdminTerminalEnum::PLATFORM;
        $request->controllerName = 'OpenPlatform';
        $request->actionName = 'saveConfig';
        $request->params = ['developer_app_id'=>''];
        self::assertSame(0, $middleware->handle($request, $next)->getData()['code']);
        $request->params = ['app_id'=>'official-component'];
        self::assertSame('allowed', $middleware->handle($request, $next));
    }

}
