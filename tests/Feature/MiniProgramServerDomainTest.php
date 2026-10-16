<?php

namespace tests\Feature;

use app\common\service\wechat\MiniProgramServerDomainService;
use PHPUnit\Framework\TestCase;

final class MiniProgramServerDomainTest extends TestCase
{
    public function testRegistersDomainWithThirdPartyBeforeAssigningToMiniProgram(): void
    {
        $calls = [];
        $state = ['testing_wxa_server_domain' => 'existing.example.com', 'published_wxa_server_domain' => 'existing.example.com'];
        MiniProgramServerDomainService::ensurePlatform('tenant.example.com', function ($payload) use (&$calls, &$state) {
            $calls[] = $payload;
            if ($payload['action'] === 'add') foreach ($state as &$domains) $domains .= ';' . $payload['wxa_server_domain'];
            return $state;
        });
        self::assertSame(['get', 'add', 'get'], array_column($calls, 'action'));
        self::assertSame('tenant.example.com', $calls[1]['wxa_server_domain']);
        self::assertTrue($calls[1]['is_modify_published_together']);
        self::assertStringContainsString('existing.example.com', $state['published_wxa_server_domain']);
    }

    public function testPlatformRejectionStopsBeforeAuthorizerConfiguration(): void
    {
        $this->expectExceptionMessage('第三方平台服务器域名未生效');
        MiniProgramServerDomainService::ensurePlatform('tenant.example.com', fn($payload) => ['testing_wxa_server_domain' => '', 'invalid_wxa_server_domain' => 'tenant.example.com']);
    }

    public function testAddsOnlyMissingTenantDomainsThenReadsBack(): void
    {
        $calls = [];
        $domains = ['requestdomain' => ['https://other.example.com']];
        $result = MiniProgramServerDomainService::ensure('https://tenant.example.com/', function (array $payload) use (&$calls, &$domains): array {
            $calls[] = $payload;
            if ($payload['action'] === 'add') {
                foreach ($payload as $key => $values) if ($key !== 'action') $domains[$key] = array_merge($domains[$key] ?? [], $values);
            }
            return $domains;
        });
        self::assertSame(['get', 'add', 'get'], array_column($calls, 'action'));
        self::assertSame(['https://tenant.example.com'], $calls[1]['requestdomain']);
        self::assertContains('https://other.example.com', $result['requestdomain']);
        self::assertSame(['wss://tenant.example.com'], $result['wsrequestdomain']);
        $calls = [];
        MiniProgramServerDomainService::ensure('https://tenant.example.com/', function ($payload) use (&$calls, $result) { $calls[] = $payload; return $result; });
        self::assertSame(['get'], array_column($calls, 'action'));
    }

    public function testRefusesUnverifiedWechatSuccess(): void
    {
        $this->expectExceptionMessage('域名回读校验失败');
        MiniProgramServerDomainService::ensure('https://tenant.example.com/', fn($payload) => []);
    }

    public function testWechatErrorIsActionableAndDoesNotLeakToken(): void
    {
        $this->expectExceptionMessage('第三方平台');
        MiniProgramServerDomainService::ensure('https://tenant.example.com/', function ($payload) { throw new \RuntimeException('invalid domain'); });
    }
}
