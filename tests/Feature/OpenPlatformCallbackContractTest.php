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
        self::assertStringContainsString('public static function authorizationLaunchUrl', $source);
        self::assertStringContainsString('public static function authorizationLaunchPage', $source);

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
        self::assertStringContainsString('route/message appid differ', $callback);
        self::assertStringContainsString('authorizationRedirect', $callback);
        self::assertStringContainsString("'/t/' . \$tenantId . '/admin/channel/overview?'", $callback);

    }

    public function testTenantAuthorizationUsesPlatformHostedEntryRelay(): void
    {
        $route = $this->source('route/app.php');
        $service = $this->source('app/common/service/wechat/OpenPlatformService.php');
        $middleware = $this->source('app/common/http/middleware/LikeAdminAllowMiddleware.php');

        self::assertStringContainsString("wechat/open-platform/authorize", $route);
        self::assertStringContainsString('authorizationLaunchPage', $route);
        self::assertStringContainsString("'Cache-Control' => 'no-store'", $route);
        self::assertStringContainsString('window.location.replace', $service);
        self::assertStringContainsString('signedAuthState', $service);
        self::assertStringContainsString("'httponly' => true", $service);
        self::assertStringContainsString("'samesite' => 'lax'", $service);
        self::assertStringContainsString('authStateCookieName', $route);
        self::assertStringContainsString('The WeChat console validates the browser', $service);
        self::assertStringContainsString("\$normalizedPath === 'wechat/open-platform/authorize'", $middleware);
        self::assertStringContainsString('platform-owned public endpoints', $middleware);
    }

    public function testCallbackFallsBackToSameOriginSignedStateCookie(): void
    {
        $callback = $this->source('app/common/service/wechat/OpenPlatformCallbackService.php');
        $route = $this->source('route/app.php');

        self::assertStringContainsString('componentloginpage does not reliably round-trip', $callback);
        self::assertStringContainsString('$request->cookie(OpenPlatformService::authStateCookieName()', $callback);
        self::assertStringContainsString('authStateCookieOptions(-3600)', $route);
        self::assertStringContainsString('componentloginpage can treat a bare 302', $route);
        self::assertStringContainsString('window.location.replace', $route);
        self::assertStringContainsString("'text/html; charset=utf-8'", $route);
    }

    public function testSignedStateRecoversTenantContextWithoutCache(): void
    {
        $reflection = new \ReflectionClass(OpenPlatformService::class);
        $make = $reflection->getMethod('signedAuthState');
        $parse = $reflection->getMethod('signedAuthStateContext');
        $make->setAccessible(true);
        $parse->setAccessible(true);
        $config = ['app_secret' => 'test-component-secret'];
        $context = ['tenant_id' => 42, 'authorizer_type' => 'miniprogram', 'created_at' => time()];

        $state = $make->invoke(null, $context, $config);

        self::assertSame($context, $parse->invoke(null, $state, $config));
        self::assertNull($parse->invoke(null, substr_replace($state, '0', -1), $config));
    }
}
