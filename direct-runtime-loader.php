<?php
declare(strict_types=1);
// Normal Composer/ThinkPHP bootstrap, shared by HTTP and CLI.
$runtimeAutoloader=require $siteRoot.'/vendor/autoload.php';
$runtimeAutoloader->setPsr4('app\\',[$siteRoot.'/app']);
$runtimeAutoloader->set('',[$siteRoot.'/extend']);
$classMap=$runtimeAutoloader->getClassMap();
foreach($classMap as $class=>$path){
    if(str_starts_with($class,'app\\'))$classMap[$class]=$siteRoot.'/'.str_replace('\\','/',$class).'.php';
}
$runtimeAutoloader->addClassMap($classMap);
$GLOBALS['AIGC_RUNTIME_SITE_ROOT']=$siteRoot;
$GLOBALS['AIGC_RUNTIME_GENERATION']=$siteRoot;
$GLOBALS['AIGC_RUNTIME_VENDOR']=$siteRoot.'/vendor';
function aigc_runtime_generation_changed():bool { return false; }
class AigcRuntimeApp extends \think\App
{
    protected $initializers=[\think\initializer\Error::class,AigcRuntimeRegisterService::class,\think\initializer\BootService::class];
    public function __construct(string $rootPath='') { parent::__construct($rootPath?:$GLOBALS['AIGC_RUNTIME_SITE_ROOT']);$this->bind(['http'=>AigcRuntimeHttp::class]); }
    public function getBasePath():string { return $GLOBALS['AIGC_RUNTIME_SITE_ROOT'].'/app/'; }
    public function getConfigPath():string { return $GLOBALS['AIGC_RUNTIME_SITE_ROOT'].'/config/'; }
}
class AigcRuntimeHttp extends \think\Http
{
    public function __construct(\think\App $app) { parent::__construct($app);$this->setRoutePath($GLOBALS['AIGC_RUNTIME_SITE_ROOT'].'/route/'); }
}
class AigcRuntimeRegisterService
{
    public function init(\think\App $app):void
    {
        $services=[\think\service\PaginatorService::class,\think\service\ValidateService::class,\think\service\ModelService::class];
        $file=$GLOBALS['AIGC_RUNTIME_VENDOR'].'/services.php';if(is_file($file))$services=array_merge($services,include $file);
        foreach($services as $service)if(class_exists($service))$app->register($service);
    }
}
// Legacy snapshots are retired once readers exit. This never scans other sites' processes.
if(PHP_SAPI!=='cli' && (is_dir($siteRoot.'/.composer-runtime') || glob($siteRoot.'/runtime/update_workspace/legacy-retired-*')) && !is_file($siteRoot.'/.composer-runtime/active.json')){
    $stamp=$siteRoot.'/runtime/legacy-cleanup-last';
    if(!is_file($stamp)||time()-(int)filemtime($stamp)>60){
        @touch($stamp);$php=PHP_BINDIR.'/php';
        if(function_exists('proc_open')&&is_executable($php)){
            $pipes=[];$process=@proc_open(['/bin/sh','-c','exec '.escapeshellarg($php).' '.escapeshellarg($siteRoot.'/scripts/runtime-cleanup.php').' </dev/null >/dev/null 2>&1 &'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,$siteRoot);
            if(is_resource($process))proc_close($process);
        }
    }
}
return $runtimeAutoloader;
