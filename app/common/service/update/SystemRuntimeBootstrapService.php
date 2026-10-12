<?php

namespace app\common\service\update;

use app\common\model\update\UpdatePackage;
use app\common\model\update\UpdateTask;
use RuntimeException;

/** One customer operation: the 390 interpreter delegates and never runs its old completion tail. */
class SystemRuntimeBootstrapService
{
    public static function handoffFromMigration(string $outerPath): void
    {
        $packageId = 0; $bootstrapSql = false;
        foreach (debug_backtrace(0, 24) as $frame) {
            if (($frame['class'] ?? '') === 'app\\common\\service\\database\\SqlMigrationExecutor' && ($frame['function'] ?? '') === 'execute') {
                $bootstrapSql = preg_match('/^-- likeadmin:system-runtime-bootstrap-v1\s*$/m', (string)($frame['args'][0] ?? '')) === 1;
            }
            if (($frame['class'] ?? '') === SystemPackageUpdateService::class && ($frame['function'] ?? '') === 'apply') $packageId = (int)($frame['args'][0] ?? 0);
        }
        if (!$bootstrapSql) return;
        if (!$packageId) throw new RuntimeException('系统运行时自举缺少已授权更新上下文');
        if (version_compare(PHP_VERSION, '8.2.0', '<')) throw new RuntimeException('本次完整更新要求先将网站及本项目任务进程切换到 PHP 8.2，并平滑停止旧任务进程后重试');
        $task = UpdateTask::where(['type'=>'system','action'=>'apply','package_id'=>$packageId,'status'=>'running'])->order('id','desc')->findOrEmpty();
        if ($task->isEmpty()) throw new RuntimeException('系统运行时自举缺少执行任务');
        $context = array_intersect_key($_SERVER, array_flip(['HTTP_HOST','SERVER_NAME','SERVER_ADDR','SERVER_PORT','HTTPS']));
        $manager=new ComposerRuntimeUpdateService(rtrim(root_path(), '/'), '', $outerPath.'/bootstrap-payload/files/scripts/composer-runtime-runner.php');
        try {
            $result=$manager->bootstrapChild(['--bootstrap',(string)$packageId,(string)$task['id'],json_encode($context,JSON_UNESCAPED_SLASHES)]);
            $completion=$result['completion'];
        }catch(\Throwable $failure){
            $package=UpdatePackage::find($packageId);$completion=null;$uncertain=false;
            try{$completion=$manager->confirmHandoffCompletion($packageId,(int)$task['id'],(string)$package['sha256']);}
            catch(\Throwable $recoveryFailure){$uncertain=true;}
            if($completion===null){
                $message=str_replace(root_path(),'[site]/',$failure->getMessage());
                if(!$uncertain){$package->save(['status'=>'apply_failed','error'=>$message,'update_time'=>time()]);$task->save(['status'=>'failed','error'=>$message,'update_time'=>time()]);}
                else $message='更新子进程状态待核验，持久化日志已保留；请勿重新运行 SQL，待原进程结束后重试';
                \app\common\service\JsonService::fail($message)->send();exit(0);
            }
        }
        // Never resume the original 390 tail, including its catch that could overwrite a committed upgrade.
        \app\common\service\JsonService::success('系统更新成功', $completion)->send();
        exit(0);
    }

    public function install(int $packageId, int $taskId, array $context): array
    {
        if (PHP_SAPI !== 'cli' || version_compare(PHP_VERSION,'8.2.0','<')) throw new RuntimeException('运行时自举要求 PHP 8.2 CLI');
        $package=UpdatePackage::where(['id'=>$packageId,'type'=>'system'])->findOrEmpty();
        $task=UpdateTask::where(['id'=>$taskId,'package_id'=>$packageId,'type'=>'system','action'=>'apply','status'=>'running'])->findOrEmpty();
        if($package->isEmpty() || $task->isEmpty())throw new RuntimeException('自举更新任务绑定无效');
        (new SystemPackageGrantService())->ensure($package->toArray());
        $extractor=new PackageExtractService();
        $unpacked=$extractor->extract((string)$package['local_path'],(string)$package['format']);
        if(($unpacked['extract_status']??'')!=='success')throw new RuntimeException('自举更新包解压失败');
        $outer=$unpacked['path'];$extractor->verifySignatureManifest($outer);
        $manifest=json_decode((string)file_get_contents($outer.'/update.json'),true,512,JSON_THROW_ON_ERROR);
        $spec=$manifest['runtime_bootstrap']??[];
        if(($spec['schema']??null)!==1 || ($spec['payload_dir']??'')!=='bootstrap-payload' || ($manifest['base_version']??'')!=='1.0.390' || ($manifest['version']??'')!=='1.0.391' || ($package['version']??'')!=='1.0.391' || ($spec['min_php']??'')!=='8.2.0')throw new RuntimeException('自举仅支持已签名 1.0.390 到完整 1.0.391');
        $this->assertCompleteSignature($outer);
        $inner=$outer.'/bootstrap-payload';$extractor->verifySignatureManifest($inner);$this->assertCompleteSignature($inner);
        $next=json_decode((string)file_get_contents($inner.'/update.json'),true,512,JSON_THROW_ON_ERROR);
        if(($next['base_version']??'')!=='1.0.390' || ($next['version']??'')!=='1.0.391' || !isset($next['composer_runtime']))throw new RuntimeException('完整自举 payload 版本或依赖声明无效');
        require_once __DIR__.'/ProjectUpdateProcessGate.php';
        ProjectUpdateProcessGate::assertDrained(root_path());
        $root=rtrim(root_path(),'/');$manager=new ComposerRuntimeUpdateService($root,'',dirname(__DIR__,4).'/scripts/composer-runtime-runner.php');
        $binding=['package_id'=>$packageId,'task_id'=>$taskId,'context'=>$context,'kind'=>'system','archive_sha256'=>(string)$package['sha256']];
        $encoded=json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $result=$manager->apply($inner,$next,[
            'immutable_code'=>true,'bootstrap_context'=>$binding,
            'sql'=>fn(string $generation)=>$manager->bootstrapChild(['--bootstrap-prepare',$generation,$inner,$encoded]),
            'files'=>fn()=> $this->copySharedFiles($inner,$next),
            'finalize'=>fn(string $generation)=>['prepared'=>true],
            'marker'=>fn()=> ProjectUpdateProcessGate::assertDrained(root_path()),
            'complete'=>fn(string $generation)=>$manager->bootstrapChild(['--bootstrap-complete',$generation,$inner,$encoded])['completion'],
        ]);
        return ['passed'=>true,'completion'=>$result['completion']];
    }

    private function assertCompleteSignature(string $root): void
    {
        $signature=json_decode((string)file_get_contents($root.'/signature.json'),true,512,JSON_THROW_ON_ERROR);$hashes=$signature['sha256']??[];
        foreach($signature['files']??[] as $entry)$hashes[$entry['path']]=$entry['sha256'];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink() || !$file->isFile())throw new RuntimeException('自举包不允许链接或特殊文件');
            $relative=substr($file->getPathname(),strlen($root)+1);
            if($relative==='signature.json')continue;
            if(!isset($hashes[$relative]) || !hash_equals((string)$hashes[$relative],(string)hash_file('sha256',$file->getPathname())))throw new RuntimeException('自举文件缺少完整签名: '.$relative);
        }
    }

    public function copySharedFiles(string $extract, array $manifest): void
    {
        ProjectUpdateProcessGate::assertDrained(root_path());
        foreach((array)($manifest['full_replace_dirs']??[]) as $directory){
            if(!in_array($directory,['public/admin','public/platform','public/pc','public/_nuxt','public/media','public/mobile','public/mp-weixin','public/static'],true))throw new RuntimeException('自举覆盖目录无效');
            $this->remove(root_path().$directory);
        }
        $files=$extract.'/files';$shared=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($files,\FilesystemIterator::SKIP_DOTS)) as $file){
            $relative=substr($file->getPathname(),strlen($files)+1);
            if(in_array(explode('/',$relative)[0],ComposerRuntimeUpdateService::CODE_DIRS,true))continue;
            $shared[$relative]=$file->getPathname();
        }
        // Genuine390 has no loader. Install its dependency before exposing either new entry.
        $rank=static fn(string $path):int=>$path==='runtime-loader.php'?0:(in_array($path,['think','public/index.php'],true)?100:10);
        uksort($shared,static fn(string $a,string $b):int=>($rank($a)<=>$rank($b))?:strcmp($a,$b));
        foreach($shared as $relative=>$source){
            $target=root_path().$relative;if(!is_dir(dirname($target))&&!mkdir(dirname($target),0755,true))throw new RuntimeException('无法创建更新目录');
            $temporary=dirname($target).'/.'.basename($target).'.runtime-update-'.bin2hex(random_bytes(12));
            try {
                if(!copy($source,$temporary))throw new RuntimeException('共享更新文件复制失败');
                $mode=is_file($target)?(fileperms($target)&0777):(fileperms($source)&0777);
                if(!chmod($temporary,$mode)||!rename($temporary,$target))throw new RuntimeException('共享更新文件原子替换失败');
            } finally {if(is_file($temporary))unlink($temporary);}
        }
        foreach((array)($manifest['delete_files']??[]) as $relative)if(!in_array(explode('/',$relative)[0],ComposerRuntimeUpdateService::CODE_DIRS,true))$this->remove(root_path().$relative);
    }

    private function remove(string $path):void
    {
        if(is_link($path))throw new RuntimeException('更新目标不能是链接');
        if(is_dir($path)){foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry)$this->remove($entry->getPathname());if(!rmdir($path))throw new RuntimeException('删除更新目录失败');}
        elseif(file_exists($path)&&!unlink($path))throw new RuntimeException('删除更新文件失败');
    }

}
