<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$serviceFile = $root . '/app/common/service/update/ComposerRuntimeUpdateService.php';
if (!is_file($serviceFile)) { fwrite(STDERR, "FAIL: immutable Composer runtime updater is missing\n"); exit(1); }
require $serviceFile;
use app\common\service\update\ComposerRuntimeUpdateService;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function put(string $file, string $content): void { if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true); file_put_contents($file, $content); }
function autoloadFixture(string $value): string {
    return '<?php /* ComposerAutoloaderInitFixture */ namespace think { class App { protected $initializers=[]; protected $rootPath; protected $runtimePath; public function __construct($p=""){$this->rootPath=$p."/";$this->runtimePath=$p."/runtime/";} public function bind($v){} protected function load():void{} public function loadConfig(){} public function getRootPath(){return $this->rootPath;} public function getRuntimePath(){return $this->runtimePath;} } class Http { protected $p; public function __construct(App $a){} public function setRoutePath($p){$this->p=$p;} public function getRoutePath(){return $this->p;} } } namespace { class RuntimeDependency { const VALUE='.var_export($value,true).'; } class FixtureLoader { private $map=[]; public function __construct(){spl_autoload_register(function($c){foreach($this->map as $prefix=>$paths)if(str_starts_with($c,$prefix)){foreach($paths as $path){$file=$path."/".str_replace("\\\\","/",substr($c,strlen($prefix))).".php";if(is_file($file)){require $file;return;}}}});} public function setPsr4($p,$v){$this->map[$p]=$v;} public function set($p,$v){} public function getClassMap(){return [];} public function addClassMap($v){} } return new FixtureLoader(); }';
}
function fixture(string $root, string $value): void {
    put($root . '/composer.json', json_encode(['require'=>['php'=>'>=8.2'],'autoload'=>['psr-4'=>['app\\'=>'app/']]]));
    put($root . '/composer.lock', json_encode(['packages'=>[]]));
    put($root . '/vendor/autoload.php', autoloadFixture($value . '-dependency'));
    put($root . '/vendor/services.php', '<?php return [];');
    put($root . '/vendor/composer/installed.php', '<?php return ["versions"=>[]];');
    put($root . '/vendor/composer/installed.json', json_encode(['packages'=>[]]));
    put($root . '/vendor/composer/autoload_real.php', '<?php class ComposerAutoloaderInitFixture {}');
    put($root . '/app/Lazy.php', '<?php namespace app; class Lazy { const VALUE=' . var_export($value,true) . '; }');
    put($root . '/config/project.php', "<?php return ['version'=>'1.0.391'];");
}
$workspace = sys_get_temp_dir().'/aigc-runtime-test-'.bin2hex(random_bytes(5));
$site=$workspace.'/site'; $package=$workspace.'/package';
fixture($site,'old'); fixture($package.'/composer-runtime','new');
unlink($package.'/composer-runtime/app/Lazy.php'); rmdir($package.'/composer-runtime/app'); unlink($package.'/composer-runtime/config/project.php'); rmdir($package.'/composer-runtime/config');
put($site.'/.env','KEEP_ENV'); put($site.'/public/uploads/task.bin','KEEP_UPLOAD'); put($site.'/runtime/tasks/queue.json','KEEP_TASK');
put($package.'/files/app/Lazy.php', '<?php namespace app; class Lazy { const VALUE="new"; }');
put($package.'/files/config/project.php', "<?php return ['version'=>'1.0.392'];");
$manifest=['version'=>'1.0.392','base_version'=>'1.0.391','package_mode'=>'incremental','full_replace_dirs'=>[],'delete_files'=>[], 'sql_order'=>[], 'composer_runtime'=>['schema'=>1,'min_updater_version'=>'1.0.391','min_php'=>'8.2.0','payload_dir'=>'composer-runtime','required_extensions'=>['json'],'probe_classes'=>[]]];
$sign=[]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package,FilesystemIterator::SKIP_DOTS)); foreach($it as $f) if($f->isFile()) $sign[substr($f->getPathname(),strlen($package)+1)]=hash_file('sha256',$f->getPathname()); put($package.'/signature.json',json_encode(['sha256'=>$sign]));
put($site.'/app/common/service/update/ComposerRuntimeUpdateService.php',file_get_contents($serviceFile)); put($site.'/runtime-loader.php',file_get_contents($root.'/runtime-loader.php')); put($site.'/scripts/composer-runtime-runner.php',file_get_contents($root.'/scripts/composer-runtime-runner.php'));
$service=new ComposerRuntimeUpdateService($site, PHP_BINARY);
$preflight=$service->preflight($package,$manifest); check($preflight['passed']===true,'valid offline runtime rejected');
$baseline=$service->ensureBaseline(); check(is_file($baseline.'/app/Lazy.php'),'baseline code was not pinned');
$previous=file_get_contents($site.'/.composer-runtime/current.json');
$failed=false; try { $service->apply($package,$manifest, ['sql'=>function(){},'files'=>function()use($site,$package){copy($package.'/files/app/Lazy.php',$site.'/app/Lazy.php');}, 'finalize'=>function(){throw new RuntimeException('injected finalize failure');},'marker'=>function(){}]); } catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'injected');}
check($failed,'injected failure did not abort'); check(file_get_contents($site.'/.composer-runtime/current.json')===$previous,'failure changed generation pointer'); check(str_contains(file_get_contents($site.'/app/Lazy.php'),"'old'"),'failure did not restore code'); check(str_contains(file_get_contents($site.'/config/project.php'),'1.0.391'),'failure changed marker');
$result=$service->apply($package,$manifest,['sql'=>function(){},'files'=>function()use($site,$package){copy($package.'/files/app/Lazy.php',$site.'/app/Lazy.php');copy($package.'/files/config/project.php',$site.'/config/project.php');},'finalize'=>function(){return [];},'marker'=>function(){}]);
check($result['generation']!==$baseline,'success did not publish generation'); check(str_contains(file_get_contents($baseline.'/app/Lazy.php'),"'old'"),'old generation changed'); check(str_contains(file_get_contents($result['generation'].'/app/Lazy.php'),'"new"'),'new generation has stale app autoload code');
check(file_get_contents($site.'/.env')==='KEEP_ENV','env changed'); check(file_get_contents($site.'/public/uploads/task.bin')==='KEEP_UPLOAD','uploads changed'); check(file_get_contents($site.'/runtime/tasks/queue.json')==='KEEP_TASK','task runtime changed'); check(is_file($site.'/vendor/autoload.php'),'site vendor changed');
put($package.'/composer-runtime/vendor/extra.php','<?php echo "unsigned";'); $rejected=false;try{$service->preflight($package,$manifest);}catch(RuntimeException $e){$rejected=true;} check($rejected,'unsigned runtime file accepted');
echo "PASS: offline payload, immutable generations, rollback, retry, protected site state, signature coverage\n";
