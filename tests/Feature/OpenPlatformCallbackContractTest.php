<?php

namespace tests\Feature;

use app\common\service\wechat\OpenPlatformService;
use PHPUnit\Framework\TestCase;

class OpenPlatformCallbackContractTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    public function testCallbackConfigurationExposesSeparateWechatUrls(): void
    {
        $source = $this->source('app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString("'message_callback_url_display'", $source);
        self::assertStringContainsString("'authorization_domain_display'", $source);
        self::assertStringContainsString('/$APPID$/callback', $source);
        self::assertStringContainsString('必须是 HTTPS 地址', $source);
        self::assertStringContainsString('public static function authState', $source);
        self::assertStringContainsString('public static function clearAuthState', $source);

        $urls = OpenPlatformService::callbackUrls([
            'callback_url' => 'https://example.test/wechat/open-platform/callback',
        ]);
        self::assertSame('https://example.test/wechat/open-platform/callback', $urls['authorization']);
        self::assertSame('https://example.test/wechat/open-platform/$APPID$/callback', $urls['message']);
    }

    public function testMessageCallbackHasAppIdRouteAndXmlResponse(): void
    {
        $route = $this->source('route/app.php');
        $callback = $this->source('app/common/service/wechat/OpenPlatformCallbackService.php');

        self::assertStringContainsString("wechat/open-platform/:appid/callback", $route);
        self::assertStringContainsString("application/xml; charset=utf-8", $route);
        self::assertStringContainsString("消息回调 AppID 与报文不一致", $callback);
        self::assertStringContainsString('authorizationRedirect', $callback);
        self::assertStringContainsString("'/t/' . \$tenantId . '/admin/channel/overview?'", $callback);

    }
}
