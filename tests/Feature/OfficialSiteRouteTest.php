<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;
use think\route\dispatch\Callback;

class OfficialSiteRouteTest extends TestCase
{
    private function router(string $method = 'GET'): App
    {
        $app = new App(dirname(__DIR__, 2));
        Container::setInstance($app);
        $app->config->set(require dirname(__DIR__, 2) . '/config/route.php', 'route');
        $request = new Request();
        $request->setMethod($method);
        $request->setHost('example.test');
        $app->instance('request', $request);
        require dirname(__DIR__, 2) . '/route/app.php';
        // check() is normally called after dispatch() binds its request.
        $property = new \ReflectionProperty(\think\Route::class, 'request');
        $property->setAccessible(true);
        $property->setValue($app->route, $request);
        return $app;
    }

    public function testPublicPagesResolveToPcEntryAndTenantPrefixesRedirect(): void
    {
        foreach (['pricing','products','scenes','cases','official/open','official/enterprise','official/help','join-opc','join-opc/payment','ai','app/aigc_canvas'] as $path) {
            $app = $this->router();
            $dispatch = $app->route->check($path, true);
            self::assertInstanceOf(Callback::class, $dispatch, $path);
            $dispatch->init($app);
            self::assertStringEndsWith('/public/pc/index.html', $dispatch->run()->getData(), $path);
            $app = $this->router();
            $dispatch = $app->route->check('t/42/' . $path, true);
            self::assertInstanceOf(Callback::class, $dispatch, 't/42/' . $path);
            $dispatch->init($app);
            self::assertSame('/' . $path . '?tenant_id=42', $dispatch->run()->getData());
        }
    }

    public function testPcFallbackDoesNotCaptureBackendOrStaticRequests(): void
    {
        foreach (['api/pc/config','tenantapi/setting.web.official_site/get','_nuxt/pricing.js','uploads/image.png'] as $path) {
            $app = $this->router();
            self::assertFalse($app->route->check($path, true), $path);
        }
        $app = $this->router('POST');
        self::assertFalse($app->route->check('pricing', true));
    }

    public function testNginxFallbackMatchesOnlyOfficialPageNamespaces(): void
    {
        $config=file_get_contents(dirname(__DIR__,2).'/docker/nginx/default.conf');
        preg_match('~location \\~ (\\^/[^\\s]+) \\{\\s*try_files \\$uri \\$uri/ /pc/index.html;~',$config,$matches);
        self::assertNotEmpty($matches);
        $pattern='~'.$matches[1].'~';
        foreach (['/pricing','/pricing/','/official/help','/join-opc/payment','/t/42/pricing'] as $path) self::assertSame(1,preg_match($pattern,$path),$path);
        foreach (['/api/pc/config','/admin/official-site/official-site','/wechat/callback','/ai/task/callback','/pricing-extra','/_nuxt/pricing.js'] as $path) self::assertSame(0,preg_match($pattern,$path),$path);
    }
}
