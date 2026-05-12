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

$tenantFrontendRedirect = function (string $frontend, string $childPath = '') {
    $tenantId = request()->param('tenant_id');
    $query = array_merge(request()->get(), ['tenant_id' => $tenantId]);
    $childPath = trim($childPath, '/');
    $path = '/' . trim($frontend, '/') . '/' . ($childPath !== '' ? $childPath : '');
    return redirect($path . '?' . http_build_query($query));
};

// 平台管理后台
Route::rule('platform/:any', function () {
    return view(app()->getRootPath() . 'public/platform/index.html');
})->pattern(['any' => '[\w\/\.\-]+']);

// 租户管理后台
Route::rule('admin', function () {
    return view(app()->getRootPath() . 'public/admin/index.html');
});
Route::rule('admin/:any', function () {
    return view(app()->getRootPath() . 'public/admin/index.html');
})->pattern(['any' => '[\w\/\.\-]+']);

Route::rule('t/:tenant_id/admin/:any', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('admin', (string)request()->param('any', ''));
})->pattern(['tenant_id' => '\d+', 'any' => '[\w\/\.\-]+']);
Route::rule('t/:tenant_id/admin', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('admin');
})->pattern(['tenant_id' => '\d+']);

// 手机端
Route::rule('mobile', function () {
    return view(app()->getRootPath() . 'public/mobile/index.html');
});
Route::rule('mobile/:any', function () {
    return view(app()->getRootPath() . 'public/mobile/index.html');
})->pattern(['any' => '[\w\/\.\-]+']);

Route::rule('t/:tenant_id/mobile/:any', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('mobile', (string)request()->param('any', ''));
})->pattern(['tenant_id' => '\d+', 'any' => '[\w\/\.\-]+']);
Route::rule('t/:tenant_id/mobile', function () use ($tenantFrontendRedirect) {
    return $tenantFrontendRedirect('mobile');
})->pattern(['tenant_id' => '\d+']);

// PC端
Route::rule('pc', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
});
Route::rule('pc/:any', function () {
    return view(app()->getRootPath() . 'public/pc/index.html');
})->pattern(['any' => '[\w\/\.\-]+']);

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
