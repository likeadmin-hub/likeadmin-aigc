<?php
// Run with PHP 8.2: php tests/routing/pc_wechat_callback.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

function routerFor(string $method, array $query): \think\App {
    $app = new \think\App(dirname(__DIR__, 2));
    \think\Container::setInstance($app);
    $app->config->set(require dirname(__DIR__, 2) . '/config/route.php', 'route');
    $request = new \think\Request();
    $request->setMethod($method);
    $request->setHost('example.test');
    $request->withGet($query);
    $app->instance('request', $request);
    require dirname(__DIR__, 2) . '/route/app.php';
    $property = new \ReflectionProperty(\think\Route::class, 'request');
    $property->setAccessible(true);
    $property->setValue($app->route, $request);
    return $app;
}
function check($condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
}

$query = ['tenant_id' => '8', 'state' => str_repeat('a', 64), 'code' => 'oauth-one-use'];
foreach (['wechat/callback', 'pc/wechat/callback', 't/8/wechat/callback', 't/8/pc/wechat/callback'] as $path) {
    $app = routerFor('GET', $query);
    $dispatch = $app->route->check($path, true);
    check($dispatch instanceof \think\route\dispatch\Callback, $path . ' must resolve to the PC OAuth entry');
    $dispatch->init($app);
    $response = $dispatch->run();
    if (str_starts_with($path, 't/')) {
        $url = $response->getData();
        parse_str((string)parse_url($url, PHP_URL_QUERY), $actual);
        check($actual === $query, $path . ' must retain code, state and tenant');
        check(parse_url($url, PHP_URL_PATH) === (str_contains($path, '/pc/') ? '/pc/wechat/callback' : '/wechat/callback'), 'Tenant callback must stay on the local PC callback');
    } else {
        check(str_ends_with($response->getData(), '/public/pc/index.html'), $path . ' must render the PC entry');
    }
}
foreach (['wechat/callback/extra', 'wechat/unknown', 'api/unknown', 'wechat/open-platform/unknown'] as $path) {
    check(routerFor('GET', [])->route->check($path, true) === false, 'OAuth fallback must not capture ' . $path);
}
check(routerFor('POST', [])->route->check('wechat/callback', true) === false, 'PC OAuth callback must remain GET only');
echo "PC WeChat callback routes passed (4 forms, query preservation, strict boundaries)\n";
