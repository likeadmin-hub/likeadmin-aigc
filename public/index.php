<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;

// A fresh checkout must reach the environment checker before loading dependencies.
$url = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$whe = !str_starts_with($url, '/platformapi/config/getConfig') && !str_starts_with($url, '/tenantapi/config/getConfig') && !str_starts_with($url, '/api/pc/config') && !str_starts_with($url, '/api/index/config');
$siteRoot = dirname(__DIR__);
$pendingRuntime = is_file($siteRoot . '/runtime/direct-update-active.json')
    || is_file($siteRoot . '/.composer-runtime/active.json')
    || is_file($siteRoot . '/.composer-runtime/current.json');
if ($whe && !is_file($siteRoot . '/config/install.lock') && !$pendingRuntime) {
    header('Location: /install/install.php');
    exit;
}
if (PHP_VERSION_ID < 80200) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('当前网站 PHP 版本为 ' . PHP_VERSION . '，系统要求 PHP 8.2 或更高版本。请在网站设置中切换 PHP 版本后重试。');
}

try {
    require __DIR__ . '/../runtime-loader.php';
} catch (\Throwable $e) {
    error_log('AIGC runtime bootstrap failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('应用运行时暂不可用，请联系管理员检查更新恢复状态。');
}

$app = new \AigcRuntimeApp(dirname(__DIR__));
if ($whe && !file_exists(__DIR__ . '/../config/install.lock')) {
    header("location:/install/install.php");
    exit;
}

define('ROOT_PATH', __DIR__);

// 执行HTTP应用并响应
$http = $app->http;

$response = $http->run();

$response->send();

$http->end($response);


function loadEnv(): array
{

    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return [];
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $env = [];

    foreach ($lines as $line) {
        // 跳过注释行
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        // 跳过不包含=行
        if (!str_contains($line, '=')) {
            continue;
        }

        // 分割键值
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // 移除值两端的引号
        if (preg_match('/^"(.*)"$/', $value, $m) || preg_match('/^\'(.*)\'$/', $value, $m)) {
            $value = $m[1];
        }

        // 处理转义字符（如\n）
        $value = str_replace('\\n', "\n", $value);

        // 存储到环境和全局变量
        $env[$key] = $value;
    }

    return $env;
}
