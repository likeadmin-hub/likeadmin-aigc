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
