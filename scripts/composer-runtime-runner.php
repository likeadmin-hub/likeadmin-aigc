<?php

declare(strict_types=1);

// Deliberately CLI-only. Payload validation and application synchronization run
// in a fresh interpreter; the installer never mixes old and new loaded classes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $siteRoot=dirname(__DIR__);
    if(($argv[1]??'')==='--site-root'){ $siteRoot=(string)$argv[2];array_splice($argv,1,2); }
    $GLOBALS['AIGC_RUNTIME_SITE_ROOT_OVERRIDE']=$siteRoot;
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
        require (is_file(($argv[2]??'').'/runtime-loader.php') ? $argv[2].'/runtime-loader.php' : $siteRoot.'/runtime-loader.php');
        $app = new AigcRuntimeApp($siteRoot);
        $generation = $GLOBALS['AIGC_RUNTIME_GENERATION'];
        if ($app->getRootPath() !== $siteRoot . '/' || $app->getBasePath() !== $generation . '/app/' || $app->getConfigPath() !== $generation . '/config/') throw new RuntimeException('运行时应用目录解析不一致');
        if ((new AigcRuntimeHttp($app))->getRoutePath() !== $generation . '/route/') throw new RuntimeException('运行时路由目录解析不一致');
        $services = include $GLOBALS['AIGC_RUNTIME_VENDOR'] . '/services.php';
        if (!is_array($services)) throw new RuntimeException('运行时服务发现清单无效');
        foreach ($services as $class) if (!is_string($class) || !class_exists($class)) throw new RuntimeException('运行时服务无法自动加载');
        echo json_encode(['passed'=>true,'services'=>$services], JSON_UNESCAPED_SLASHES);
    } elseif ($mode === '--finalize') {
        $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'] = $argv[2] ?? '';
        $context = json_decode($argv[5] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        foreach (['HTTP_HOST','SERVER_NAME','SERVER_ADDR','SERVER_PORT','HTTPS'] as $name) if (isset($context[$name])) $_SERVER[$name] = $context[$name];
        require (is_file(($argv[2]??'').'/runtime-loader.php') ? $argv[2].'/runtime-loader.php' : $siteRoot.'/runtime-loader.php');
        $app = new AigcRuntimeApp($siteRoot);
        $app->initialize();
        $result = (new \app\common\service\update\SystemPackageUpdateService())->finalizeRuntimeApps((string)$argv[3], (string)$argv[4]);
        echo json_encode(['passed'=>true, 'builtin_apps'=>$result], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } elseif ($mode === '--bootstrap') {
        $context=json_decode($argv[4]??'{}',true,512,JSON_THROW_ON_ERROR);
        foreach($context as $name=>$value)$_SERVER[$name]=$value;
        require $siteRoot.'/vendor/autoload.php';
        $app=new \think\App($siteRoot);$app->initialize();
        require_once dirname(__DIR__).'/app/common/service/update/ComposerRuntimeUpdateService.php';
        require_once dirname(__DIR__).'/app/common/service/update/SystemRuntimeBootstrapService.php';
        $result=(new \app\common\service\update\SystemRuntimeBootstrapService())->install((int)$argv[2],(int)$argv[3],$context);
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } elseif (in_array($mode,['--bootstrap-prepare','--bootstrap-complete','--bootstrap-recover'],true)) {
        $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']=$argv[2]??'';
        $binding=json_decode($argv[4]??'{}',true,512,JSON_THROW_ON_ERROR);
        foreach($binding['context']??[] as $name=>$value)$_SERVER[$name]=$value;
        require (is_file(($argv[2]??'').'/runtime-loader.php') ? $argv[2].'/runtime-loader.php' : $siteRoot.'/runtime-loader.php');
        $app=new AigcRuntimeApp($siteRoot);$app->initialize();
        $service=new \app\common\service\update\SystemPackageUpdateService();
        if($mode==='--bootstrap-prepare')$result=['passed'=>true,'prepared'=>$service->prepareBootstrapSql($argv[3])];
        elseif($mode==='--bootstrap-complete')$result=['passed'=>true,'completion'=>$service->completeBootstrap($argv[3],$binding)];
        else {
            $package=\app\common\model\update\UpdatePackage::find((int)$binding['package_id']);
            $task=\app\common\model\update\UpdateTask::find((int)$binding['task_id']);
            $completion=$task?$task['result_json']:[];
            $committed=$package && $task && (int)$task['package_id']===(int)$package['id'] && $task['action']==='apply' && $package['status']==='applied' && $task['status']==='success' && ($completion['composer_runtime']['generation']??'')===$GLOBALS['AIGC_RUNTIME_GENERATION'];
            if ($committed) {
                if ($package['type'] !== 'system' || $task['type'] !== 'system' || !hash_equals((string)$package['sha256'],(string)($binding['archive_sha256']??''))) throw new RuntimeException('已提交系统任务与恢复绑定不一致');
                $project=(string)file_get_contents($siteRoot.'/config/project.php');
                preg_match("/['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",$project,$matched);
                $marker=json_decode((string)file_get_contents($siteRoot.'/upgrade/version.json'),true);
                if (($matched[1]??'')!==(string)$package['version'] || ($marker['version']??'')!==(string)$package['version']) throw new RuntimeException('已提交系统版本标记不一致，禁止自动回滚数据库对应代码');
            }
            $result=['passed'=>true,'committed'=>(bool)$committed,'completion'=>$completion];
        }
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } elseif(in_array($mode,['--app-runtime-prepare','--app-runtime-complete','--app-runtime-recover'],true)) {
        $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']=$argv[2]??'';
        $binding=json_decode($argv[4]??'{}',true,512,JSON_THROW_ON_ERROR);
        foreach($binding['context']??[] as $name=>$value)$_SERVER[$name]=$value;
        require (is_file(($argv[2]??'').'/runtime-loader.php') ? $argv[2].'/runtime-loader.php' : $siteRoot.'/runtime-loader.php');
        $app=new AigcRuntimeApp($siteRoot);$app->initialize();
        $service=new \app\common\service\update\AppPackageUpdateService();
        if($mode==='--app-runtime-prepare')$result=['passed'=>true,'prepared'=>$service->prepareRuntimeAppPackage($argv[3],$binding)];
        elseif($mode==='--app-runtime-complete')$result=['passed'=>true,'completion'=>$service->completeRuntimeAppPackage($argv[3],$binding)];
        else {
            $package=\app\common\model\update\UpdatePackage::find((int)$binding['package_id']);
            $task=\app\common\model\update\UpdateTask::find((int)$binding['task_id']);$completion=$task?$task['result_json']:[];
            $committed=$package && $task && (int)$task['package_id']===(int)$package['id'] && $task['action']==='apply' && $package['status']==='applied' && $task['status']==='success' && ($completion['composer_runtime']['generation']??'')===$GLOBALS['AIGC_RUNTIME_GENERATION'];
            $result=['passed'=>true,'committed'=>(bool)$committed,'completion'=>$completion];
        }
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    } else throw new RuntimeException('不支持的运行时更新子进程模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
