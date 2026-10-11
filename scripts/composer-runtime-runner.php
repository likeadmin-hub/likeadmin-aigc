<?php

declare(strict_types=1);

// Deliberately CLI-only. Payload validation and application synchronization run
// in a fresh interpreter; the installer never mixes old and new loaded classes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $mode = $argv[1] ?? '';
    if ($mode === '--probe') {
        $payload = $argv[2] ?? '';
        $spec = json_decode($argv[3] ?? '', true, 512, JSON_THROW_ON_ERROR);
        if (version_compare(PHP_VERSION, (string)$spec['min_php'], '<')) throw new RuntimeException('PHP CLI 版本不足');
        foreach ($spec['required_extensions'] as $extension) if (!extension_loaded($extension)) throw new RuntimeException('PHP CLI 缺少扩展: ' . $extension);
        // Composer platform_check plus autoload files execute here, never in the old parent.
        require $payload . '/vendor/autoload.php';
        foreach ($spec['probe_classes'] as $class) if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) throw new RuntimeException('Composer 运行时缺少类: ' . $class);
        echo json_encode(['passed'=>true, 'php_version'=>PHP_VERSION, 'extensions'=>get_loaded_extensions()], JSON_UNESCAPED_SLASHES);
    } elseif ($mode === '--candidate-probe') {
        $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'] = $argv[2] ?? '';
        require dirname(__DIR__) . '/runtime-loader.php';
        $app = new AigcRuntimeApp(dirname(__DIR__));
        $generation = $GLOBALS['AIGC_RUNTIME_GENERATION'];
        if ($app->getRootPath() !== dirname(__DIR__) . '/' || $app->getBasePath() !== $generation . '/app/' || $app->getConfigPath() !== $generation . '/config/') throw new RuntimeException('运行时应用目录解析不一致');
        if ((new AigcRuntimeHttp($app))->getRoutePath() !== $generation . '/route/') throw new RuntimeException('运行时路由目录解析不一致');
        $services = include $GLOBALS['AIGC_RUNTIME_VENDOR'] . '/services.php';
        if (!is_array($services)) throw new RuntimeException('运行时服务发现清单无效');
        foreach ($services as $class) if (!is_string($class) || !class_exists($class)) throw new RuntimeException('运行时服务无法自动加载');
        echo json_encode(['passed'=>true,'services'=>$services], JSON_UNESCAPED_SLASHES);
    } elseif ($mode === '--finalize') {
        $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'] = $argv[2] ?? '';
        $context = json_decode($argv[5] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        foreach (['HTTP_HOST','SERVER_NAME','SERVER_ADDR','SERVER_PORT','HTTPS'] as $name) if (isset($context[$name])) $_SERVER[$name] = $context[$name];
        require dirname(__DIR__) . '/runtime-loader.php';
        $app = new AigcRuntimeApp(dirname(__DIR__));
        $app->initialize();
        $result = (new \app\common\service\update\SystemPackageUpdateService())->finalizeRuntimeApps((string)$argv[3], (string)$argv[4]);
        echo json_encode(['passed'=>true, 'builtin_apps'=>$result], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } else throw new RuntimeException('不支持的运行时更新子进程模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
