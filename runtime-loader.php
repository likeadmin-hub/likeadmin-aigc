<?php

declare(strict_types=1);

/** Bridge bootstrap: each process pins one immutable application/runtime generation. */
$siteRoot = $GLOBALS['AIGC_RUNTIME_SITE_ROOT_OVERRIDE'] ?? __DIR__;
if(!class_exists('app\\common\\service\\update\\ComposerRuntimeUpdateService',false)){
    $managerFile=__DIR__.'/app/common/service/update/ComposerRuntimeUpdateService.php';
    if(!is_file($managerFile)){
        $pointer=json_decode((string)@file_get_contents($siteRoot.'/.composer-runtime/current.json'),true);
        $generationId=(string)($pointer['generation']??'');
        if(is_file($siteRoot.'/.composer-runtime/active.json')){
            $active=json_decode((string)file_get_contents($siteRoot.'/.composer-runtime/active.json'),true);
            $digest=(string)($active['digest']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new \RuntimeException('运行时恢复日志无效');
            $journal=json_decode((string)file_get_contents($siteRoot.'/.composer-runtime/journals/'.$digest.'.json'),true);
            $generationId=(string)($journal['generation']??'');
        }
        if(!preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}$/D',$generationId))throw new \RuntimeException('运行时加载器缺少已安装的完整版本');
        $generationRoot=$siteRoot.'/.composer-runtime/generations/'.$generationId;
        if(is_link($generationRoot))throw new \RuntimeException('运行时加载目录不能是链接');
        $managerFile=$generationRoot.'/app/common/service/update/ComposerRuntimeUpdateService.php';
    }
    require_once $managerFile;
}
$runtimeManager = new \app\common\service\update\ComposerRuntimeUpdateService($siteRoot);
$runtimePinnedPrevious=empty($GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'])?$runtimeManager->recoverBeforeBootstrap():null;
// Read-only deployments and the installer can boot without writing runtime state.
$runtimeGeneration = $GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'] ?? $runtimePinnedPrevious ?? (is_file($siteRoot . '/.composer-runtime/current.json') ? $runtimeManager->ensureBaseline() : $siteRoot);
$generationBase = $siteRoot . '/.composer-runtime/generations/';
if ($runtimeGeneration !== $siteRoot && (!is_string($runtimeGeneration) || !str_starts_with($runtimeGeneration, $generationBase)
    || !preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}$/', substr($runtimeGeneration, strlen($generationBase)))
    || is_link($runtimeGeneration))) {
    throw new \RuntimeException('Composer 运行时版本目录无效');
}
$generationMetadata = $runtimeGeneration === $siteRoot ? ['schema'=>1,'vendor'=>'site'] : json_decode((string)file_get_contents($runtimeGeneration . '/generation.json'), true);
if (!is_array($generationMetadata) || ($generationMetadata['schema'] ?? null) !== 1) throw new \RuntimeException('Composer 运行时版本清单无效');
$runtimeVendor = ($generationMetadata['vendor'] ?? '') === 'site' ? $siteRoot . '/vendor' : $runtimeGeneration . '/vendor';
$runtimeAutoloader = require $runtimeVendor . '/autoload.php';
$runtimeAutoloader->setPsr4('app\\', [$runtimeGeneration . '/app']);
$runtimeAutoloader->set('', [$runtimeGeneration . '/extend']);
$classMap = $runtimeAutoloader->getClassMap();
foreach ($classMap as $class => $path) {
    foreach (['app', 'extend'] as $directory) {
        $sourcePrefix = dirname($runtimeVendor) . '/' . $directory . '/';
        if (str_starts_with($path, $sourcePrefix)) $classMap[$class] = $runtimeGeneration . '/' . $directory . '/' . substr($path, strlen($sourcePrefix));
    }
}
$runtimeAutoloader->addClassMap($classMap);
$GLOBALS['AIGC_RUNTIME_SITE_ROOT'] = $siteRoot;
$GLOBALS['AIGC_RUNTIME_GENERATION'] = $runtimeGeneration;
$GLOBALS['AIGC_RUNTIME_VENDOR'] = $runtimeVendor;

function aigc_runtime_generation_changed(): bool
{
    if ($GLOBALS['AIGC_RUNTIME_GENERATION'] === $GLOBALS['AIGC_RUNTIME_SITE_ROOT'] && !is_file($GLOBALS['AIGC_RUNTIME_SITE_ROOT'] . '/.composer-runtime/current.json')) return false;
    $pointer = json_decode((string)@file_get_contents($GLOBALS['AIGC_RUNTIME_SITE_ROOT'] . '/.composer-runtime/current.json'), true);
    return is_array($pointer) && ($pointer['generation'] ?? '') !== basename($GLOBALS['AIGC_RUNTIME_GENERATION']);
}

/** Keep mutable site paths stable while all executable application files are pinned. */
class AigcRuntimeApp extends \think\App
{
    protected $initializers = [\think\initializer\Error::class, AigcRuntimeRegisterService::class, \think\initializer\BootService::class];

    public function __construct(string $rootPath = '')
    {
        $generation = $GLOBALS['AIGC_RUNTIME_GENERATION'];
        parent::__construct($generation);
        $this->rootPath = rtrim($rootPath ?: $GLOBALS['AIGC_RUNTIME_SITE_ROOT'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->runtimePath = $this->rootPath . 'runtime' . DIRECTORY_SEPARATOR;
        $this->bind(['http' => AigcRuntimeHttp::class]);
    }

    protected function load(): void { parent::load(); $this->loadConfig(); }

    public function getBasePath(): string { return $GLOBALS['AIGC_RUNTIME_GENERATION'] . '/app/'; }
    public function getConfigPath(): string { return $GLOBALS['AIGC_RUNTIME_GENERATION'] . '/config/'; }
}

class AigcRuntimeHttp extends \think\Http
{
    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->setRoutePath($GLOBALS['AIGC_RUNTIME_GENERATION'] . '/route/');
    }
}

class AigcRuntimeRegisterService
{
    public function init(\think\App $app): void
    {
        $services = [\think\service\PaginatorService::class, \think\service\ValidateService::class, \think\service\ModelService::class];
        $path = $GLOBALS['AIGC_RUNTIME_VENDOR'] . '/services.php';
        if (is_file($path)) $services = array_merge($services, include $path);
        foreach ($services as $service) if (class_exists($service)) $app->register($service);
    }
}

return $runtimeAutoloader;
