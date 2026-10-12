<?php
declare(strict_types=1);
require __DIR__.'/../../app/common/service/update/ComposerRuntimeUpdateService.php';
require __DIR__.'/../../app/common/service/update/ProjectUpdateProcessGate.php';
require __DIR__.'/../../app/common/service/update/SystemRuntimeBootstrapService.php';
$site=sys_get_temp_dir().'/aigc-entry-order-'.bin2hex(random_bytes(6));$source=$site.'-payload';
function root_path(): string { return $GLOBALS['site'].'/'; }
function orderPut(string $path,string $value):void { if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$value); }
function orderRemove(string $path):void { if(is_dir($path)){foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $child)orderRemove($child->getPathname());rmdir($path);}elseif(file_exists($path))unlink($path); }
try {
    mkdir($site);
    // Deliberately put the new entry into the directory iterator before its dependency.
    orderPut($source.'/files/public/index.php',"<?php require dirname(__DIR__).'/runtime-loader.php'; /*".str_repeat('entry payload ',800000)."*/");
    orderPut($source.'/files/think',"<?php require __DIR__.'/runtime-loader.php';");
    orderPut($source.'/files/runtime-loader.php',"<?php \$GLOBALS['loader_ready']=true;");
    chmod($source.'/files/think',0755);
    $ready=$site.'/observer-ready';$pipes=[];
    $code='file_put_contents($argv[2],"ready");$end=microtime(true)+5;while(microtime(true)<$end){if(file_exists($argv[1]."/public/index.php")||file_exists($argv[1]."/think")){if(!is_file($argv[1]."/runtime-loader.php")){fwrite(STDERR,"new entry appeared before loader");exit(1);}exit(0);}usleep(100);}exit(2);';
    $process=proc_open([PHP_BINARY,'-r',$code,$site,$ready],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$end=microtime(true)+5;while(!file_exists($ready)&&microtime(true)<$end)usleep(1000);
    if(!file_exists($ready))throw new RuntimeException('observer not ready');
    (new app\common\service\update\SystemRuntimeBootstrapService())->copySharedFiles($source,['full_replace_dirs'=>[],'delete_files'=>[]]);
    $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException($output);
    if((fileperms($site.'/think')&0777)!==0755)throw new RuntimeException('CLI executable mode lost');
    require $site.'/public/index.php';
    if(empty($GLOBALS['loader_ready']))throw new RuntimeException('copied actual entry failed to bootstrap');
    echo "PASS: independent process observes loader installed before HTTP/CLI entries; copied HTTP entry bootstraps\n";
}finally{orderRemove($site);orderRemove($source);}
