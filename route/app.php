<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Console;
use think\facade\Route;

$openPlatformCallback = function () {
    $isAuthorizationCallback = request()->isGet() && (string)request()->param('auth_code', '') !== '';
    try {
        $result = \app\common\service\wechat\OpenPlatformCallbackService::handle(request());
        if (is_array($result) && isset($result['redirect'])) {
            // componentloginpage can treat a bare 302 callback response as a
            // downloadable resource. Return an explicit HTML navigation page
            // instead so the browser always completes the tenant redirect.
            $target = (string)$result['redirect'];
            $encodedTarget = json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
            $content = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>授权完成</title><style>:root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#14213d;background:#f5f8ff}*{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;overflow:hidden;background:radial-gradient(circle at 14% 18%,#dce8ff 0,transparent 31%),radial-gradient(circle at 90% 85%,#d8f7ef 0,transparent 29%),#f7f9fe}.orb{position:fixed;border-radius:999px;filter:blur(2px);opacity:.55}.orb-a{width:280px;height:280px;background:#bbcdfd;top:-110px;right:-60px}.orb-b{width:220px;height:220px;background:#b8eedc;bottom:-90px;left:-65px}.card{position:relative;width:min(460px,calc(100vw - 40px));padding:48px 42px 38px;text-align:center;background:rgba(255,255,255,.88);border:1px solid rgba(255,255,255,.9);border-radius:28px;box-shadow:0 24px 70px rgba(30,67,130,.17);backdrop-filter:blur(18px)}.mark{width:68px;height:68px;margin:0 auto 26px;border-radius:22px;display:grid;place-items:center;background:linear-gradient(135deg,#5865f2,#23b5d3);box-shadow:0 12px 26px rgba(74,100,235,.3)}.mark:before{content:"";width:27px;height:27px;border:3px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .85s linear infinite}.eyebrow{font-size:13px;letter-spacing:.13em;color:#7583a0}.title{margin:12px 0 10px;font-size:26px;letter-spacing:.02em}.desc{margin:0;color:#6f7d96;font-size:15px;line-height:1.8}.dots span{display:inline-block;width:5px;height:5px;margin:0 3px;border-radius:50%;background:#5466ec;animation:pulse 1.2s infinite}.dots span:nth-child(2){animation-delay:.15s}.dots span:nth-child(3){animation-delay:.3s}.fallback{display:inline-flex;align-items:center;justify-content:center;min-height:44px;margin-top:28px;padding:0 20px;border-radius:12px;color:#fff;text-decoration:none;font-size:14px;background:#5264eb;box-shadow:0 8px 18px rgba(82,100,235,.25)}.hint{margin-top:16px;font-size:12px;color:#9aa6ba}@keyframes spin{to{transform:rotate(360deg)}}@keyframes pulse{50%{transform:translateY(-4px);opacity:.45}}</style></head><body><i class="orb orb-a"></i><i class="orb orb-b"></i><main class="card"><div class="mark"></div><div class="eyebrow">WECHAT OPEN PLATFORM</div><h1 class="title">授权已完成</h1><p class="desc">正在安全返回渠道配置页<br><span class="dots"><span></span><span></span><span></span></span></p><a class="fallback" href="' . $safeTarget . '">未自动跳转？点击继续</a><div class="hint">请勿关闭此页面</div></main><script>window.location.replace(' . $encodedTarget . ');</script></body></html>';
            $response = response($content, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
            return $isAuthorizationCallback ? $response->cookie(\app\common\service\wechat\OpenPlatformService::authStateCookieName(), '', \app\common\service\wechat\OpenPlatformService::authStateCookieOptions(-3600))->cookie(\app\common\service\wechat\OpenPlatformService::authReturnOriginCookieName(), '', \app\common\service\wechat\OpenPlatformService::authStateCookieOptions(-3600)) : $response;
        }
        if (is_array($result) && array_key_exists('response', $result)) {
            $result = (string)$result['response'];
        }
        $contentType = is_string($result) && str_starts_with(ltrim($result), '<xml')
            ? 'application/xml; charset=utf-8'
            : 'text/plain; charset=utf-8';
        $response = response($result, 200, ['Content-Type' => $contentType]);
        return $isAuthorizationCallback ? $response->cookie(\app\common\service\wechat\OpenPlatformService::authStateCookieName(), '', \app\common\service\wechat\OpenPlatformService::authStateCookieOptions(-3600))->cookie(\app\common\service\wechat\OpenPlatformService::authReturnOriginCookieName(), '', \app\common\service\wechat\OpenPlatformService::authStateCookieOptions(-3600)) : $response;
    } catch (\Throwable $e) {
        $response = response('fail', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        return $isAuthorizationCallback ? $response->cookie(\app\common\service\wechat\OpenPlatformService::authStateCookieName(), '', \app\common\service\wechat\OpenPlatformService::authStateCookieOptions(-3600))->cookie(\app\common\service\wechat\OpenPlatformService::authReturnOriginCookieName(), '', \app\common\service\wechat\OpenPlatformService::authStateCookieOptions(-3600)) : $response;
    }
};
Route::rule('wechat/open-platform/callback', $openPlatformCallback, 'GET|POST');
// Authorizer messages are configured with /$APPID$/ in the WeChat console;
// WeChat replaces it with the authorizer AppID before sending the request.
Route::rule('wechat/open-platform/:appid/callback', $openPlatformCallback, 'GET|POST')
    ->pattern(['appid' => '[A-Za-z0-9_-]+']);

// Tenant browsers load this page on the configured platform host before
// entering WeChat.  WeChat checks this entry-page host separately from its
// redirect_uri, so a direct tenant -> WeChat navigation is rejected.
Route::get('wechat/open-platform/authorize', function () {
    try {
        $state = (string)request()->param('state', '');
        $context = \app\common\service\wechat\OpenPlatformService::authState($state);
        return response(
            \app\common\service\wechat\OpenPlatformService::authorizationLaunchPage($state),
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']
        )->cookie(\app\common\service\wechat\OpenPlatformService::authStateCookieName(), $state, \app\common\service\wechat\OpenPlatformService::authStateCookieOptions())
            ->cookie(\app\common\service\wechat\OpenPlatformService::authReturnOriginCookieName(), \app\common\service\wechat\OpenPlatformService::authReturnOriginCookieValue((string)($context['return_origin'] ?? '')), \app\common\service\wechat\OpenPlatformService::authStateCookieOptions());
    } catch (\Throwable $e) {
        return response('授权状态已失效，请返回租户后台重新发起授权', 400, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
});

// Upstream callbacks bypass user authentication. The handler accepts POST only
// and verifies the HMAC configured with the model API source before waking a job.
Route::post('ai/task/callback', function () {
    return (new \app\common\controller\AiTaskCallbackController())->receive(request());
});

$tenantFrontendRedirect = function (string $frontend, string $childPath = '') {
    $tenantId = request()->param('tenant_id');
    $query = array_merge(request()->get(), ['tenant_id' => $tenantId]);
    $frontend = trim($frontend, '/');
    $childPath = trim($childPath, '/');
    $path = '/' . implode('/', array_filter([$frontend, $childPath], static fn ($item) => $item !== ''));
    return redirect($path . '?' . http_build_query($query));
};

// PC端默认访问站点根路径，平台端固定保留 /platform/
Route::get('/', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
});
Route::get(':path', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
})->pattern(['path' => '(ai|app|account|user|page|policy)(/.*)?']);

// 平台管理后台
Route::get('platform', function () {
    return view(app()->getRootPath() . 'public/platform/index.html');
});
Route::get('platform/:path', function () {
    return view(app()->getRootPath() . 'public/platform/index.html');
})->pattern(['path' => '.*']);

// 租户管理后台
Route::get('admin', function () {
    return view(app()->getRootPath() . 'public/admin/index.html');
});
Route::get('admin/:path', function () {
    return view(app()->getRootPath() . 'public/admin/index.html');
})->pattern(['path' => '.*']);

Route::rule('t/:tenant_id/admin/:any', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('admin', (string)request()->param('any', ''));
})->pattern(['tenant_id' => '\d+', 'any' => '[\w\/\.\-]+']);
Route::rule('t/:tenant_id/admin', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('admin');
})->pattern(['tenant_id' => '\d+']);

// 手机端
Route::get('mobile', function () {
    return view(app()->getRootPath() . 'public/mobile/index.html');
});
Route::get('mobile/:path', function () {
    return view(app()->getRootPath() . 'public/mobile/index.html');
})->pattern(['path' => '.*']);

Route::rule('t/:tenant_id/mobile/:any', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('mobile', (string)request()->param('any', ''));
})->pattern(['tenant_id' => '\d+', 'any' => '[\w\/\.\-]+']);
Route::rule('t/:tenant_id/mobile', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('mobile');
})->pattern(['tenant_id' => '\d+']);

// PC端默认不再带 /pc/ 后缀，保留 /pc/ 旧链接兼容
Route::rule('t/:tenant_id/:any', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('', (string)request()->param('any', ''));
})->pattern(['tenant_id' => '\d+', 'any' => '(ai|app|account|user|page|policy)(/.*)?']);
Route::rule('t/:tenant_id', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('');
})->pattern(['tenant_id' => '\d+']);

Route::get('pc', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
});
Route::get('pc/:path', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
})->pattern(['path' => '.*']);

Route::rule('t/:tenant_id/pc/:any', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('pc', (string)request()->param('any', ''));
})->pattern(['tenant_id' => '\d+', 'any' => '[\w\/\.\-]+']);
Route::rule('t/:tenant_id/pc', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('pc');
})->pattern(['tenant_id' => '\d+']);

//定时任务
Route::rule('crontab', function () {
    Console::call('crontab');
});
