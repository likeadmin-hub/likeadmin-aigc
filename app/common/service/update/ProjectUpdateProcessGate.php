<?php
namespace app\common\service\update;

use RuntimeException;

/** Read-only Linux process gate. Never signals processes or prints their arguments. */
class ProjectUpdateProcessGate
{
    /** Compare verified package bytes with the selected immutable runtime, not a checkout. */
    public static function inspectUpdate(string $siteRoot, string $extract, array $manifest): array
    {
        $root=realpath($siteRoot);
        if($root===false)throw new RuntimeException('无法核验更新目标目录');
        $code=$root;$vendor=$root.'/vendor';
        $pointer=$root.'/.composer-runtime/current.json';
        if(is_file($pointer)){
            $current=json_decode((string)file_get_contents($pointer),true);
            $id=$current['generation']??'';
            if(($current['schema']??null)!==1 || !is_string($id) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D',$id))throw new RuntimeException('运行时指针无效，无法判定任务进程影响');
            $store=realpath($root.'/.composer-runtime/generations');
            $code=realpath($root.'/.composer-runtime/generations/'.$id);
            if($store===false || $code===false || dirname($code)!==$store)throw new RuntimeException('运行时目录无效，无法判定任务进程影响');
            $metadata=json_decode((string)@file_get_contents($code.'/generation.json'),true);
            if(($metadata['schema']??null)!==1 || !in_array($metadata['vendor']??'', ['site','generation'],true))throw new RuntimeException('运行时元数据无效，无法判定任务进程影响');
            $vendor=$metadata['vendor']==='site'?$root.'/vendor':$code.'/vendor';
        }
        $reasons=[];
        if(isset($manifest['runtime_bootstrap']))$reasons[]='首次安装运行时隔离机制';
        foreach((array)($manifest['sql_order']??[]) as $path)$reasons[]='数据库迁移: '.self::safeRelative((string)$path);
        if(($manifest['package_mode']??'')!=='incremental')foreach(['data','structure'] as $group){
            $directory=rtrim($extract,'/').'/sql/'.$group;
            if(is_dir($directory))foreach(self::files($directory) as $path=>$source){
                if(str_ends_with(strtolower($path),'.sql') && !str_starts_with(strtolower(basename($path)),'readme.'))$reasons[]='数据库迁移: sql/'.$group.'/'.$path;
            }
        }
        $files=rtrim($extract,'/').'/files';
        if(is_dir($files))foreach(self::files($files) as $path=>$source){
            $target=(in_array(explode('/',$path)[0],['app','config','route','extend'],true)?$code:$root).'/'.$path;
            if(!self::sameFile($source,$target) && self::workerPath($path)){
                if($path==='config/project.php' && self::versionOnly($source,$target))continue;
                $reasons[]='任务运行文件: '.$path;
            }
        }
        foreach((array)($manifest['delete_files']??[]) as $path){
            $path=self::safeRelative((string)$path);
            $target=(in_array(explode('/',$path)[0],['app','config','route','extend'],true)?$code:$root).'/'.$path;
            if(file_exists($target) && self::workerPath($path))$reasons[]='删除任务运行文件: '.$path;
        }
        foreach((array)($manifest['full_replace_dirs']??[]) as $directory){
            $directory=self::safeRelative((string)$directory);
            if(!self::workerPath($directory) || !is_dir($root.'/'.$directory))continue;
            foreach(self::files($root.'/'.$directory) as $path=>$old)if(!is_file($files.'/'.$directory.'/'.$path))$reasons[]='覆盖删除任务运行文件: '.$directory.'/'.$path;
        }
        // App asset deployment has declared side effects outside the frozen app
        // directory. Only static targets can use the no-stop asset-only path.
        foreach(glob($files.'/app/apps/*/manifest.json') ?: [] as $path){
            $app=json_decode((string)file_get_contents($path),true);
            foreach((array)($app['public_assets']??[]) as $asset){
                $target=self::safeRelative((string)($asset['target']??''));
                $relative=self::safeRelative((string)($asset['source']??''));
                $source=dirname($path).'/'.$relative;
                if(!is_file($source))$source=$code.'/app/apps/'.basename(dirname($path)).'/'.$relative;
                if(self::workerPath($target) && !self::sameFile($source,$root.'/'.$target))$reasons[]='应用资源部署到任务目录: '.$target;
            }
        }
        if(isset($manifest['composer_runtime'])){
            $payload=rtrim($extract,'/').'/composer-runtime';
            $before=is_dir($vendor)?self::files($vendor):[];
            $after=is_dir($payload.'/vendor')?self::files($payload.'/vendor'):[];
            if(!$after)throw new RuntimeException('依赖包缺失，无法判定任务进程影响');
            foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $path){
                if(!isset($before[$path],$after[$path]) || !self::sameFile($before[$path],$after[$path])){$reasons[]='任务依赖: vendor/'.$path;break;}
            }
            foreach(['composer.json','composer.lock'] as $name){
                $old=is_file($code.'/'.$name)?$code.'/'.$name:$root.'/'.$name;
                if(is_file($payload.'/'.$name) && !self::sameFile($payload.'/'.$name,$old))$reasons[]='任务依赖: '.$name;
            }
        }
        $reasons=array_values(array_unique($reasons));
        return ['requires_stop'=>$reasons!==[], 'reasons'=>$reasons];
    }

    public static function assertUpdateAllowed(string $siteRoot, array $impact): void
    {
        if(empty($impact['requires_stop']))return;
        try{self::assertDrained($siteRoot);}
        catch(RuntimeException $e){throw new RuntimeException($e->getMessage().'；本次更新涉及: '.implode('；',array_slice($impact['reasons'],0,8)),0,$e);}
    }

    private static function workerPath(string $path): bool
    {
        // Admin HTTP controllers are outside the task tool adapter. Public API
        // controllers may be invoked directly by OpenToolAdapter in a worker.
        // Shared code and unknown paths therefore stay conservative.
        if(preg_match('#^public/(?:admin|platform|pc|_nuxt|media|mobile|mp-weixin|static)(?:/|$)#',$path))return false;
        if(preg_match('#^public/[^/]+\.(?:js|css|map|json|png|jpe?g|webp|gif|svg|ico|woff2?)$#i',$path))return false;
        if($path==='public/index.php')return false;
        if(preg_match('#^app/(?:tenantapi|platformapi|adminapi)/controller/#',$path))return false;
        if(preg_match('#^app/apps/[^/]+/frontend/#',$path))return false;
        if(preg_match('#^(?:docs|tests)/#',$path) || preg_match('#(?:^|/)(?:README[^/]*|[^/]+\.md)$#i',$path))return false;
        return true;
    }

    private static function versionOnly(string $source,string $target): bool
    {
        if(!is_file($target) || is_link($target))return false;
        $normalize=static function(string $content): ?string {
            $result=preg_replace('/([\'"]version[\'"]\s*=>\s*)[\'"]\d+\.\d+\.\d+[\'"]/','$1\'VERSION\'',$content,-1,$count);
            return $count===1?$result:null;
        };
        $before=$normalize((string)file_get_contents($target));$after=$normalize((string)file_get_contents($source));
        return $before!==null && $before===$after;
    }

    private static function sameFile(string $a,string $b): bool
    {
        return is_file($a) && is_file($b) && !is_link($a) && !is_link($b)
            && filesize($a)===filesize($b) && hash_file('sha256',$a)===hash_file('sha256',$b);
    }

    private static function files(string $directory): array
    {
        $result=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isLink() || !$file->isFile())throw new RuntimeException('无法判定链接或特殊文件的任务进程影响');
            $path=substr($file->getPathname(),strlen($directory)+1);self::safeRelative($path);$result[$path]=$file->getPathname();
        }
        return $result;
    }

    private static function safeRelative(string $path): string
    {
        if($path==='' || str_starts_with($path,'/') || str_contains($path,'\\') || str_contains($path,"\0") || array_intersect(explode('/',$path),['..','.','']))throw new RuntimeException('更新路径无效，无法判定任务进程影响');
        return $path;
    }

    public static function assertDrained(string $siteRoot): void
    {
        $root=realpath($siteRoot);
        if($root===false || PHP_OS_FAMILY!=='Linux' || !is_readable('/proc/mounts'))throw new RuntimeException('无法核验本项目任务进程；需可读取 Linux /proc，平滑停止本项目 Supervisor 和 cron 后重试');
        if(preg_match('/(?:^|,)hidepid=(?:1|2|4)(?:,|\s)/m',(string)file_get_contents('/proc/mounts')))throw new RuntimeException('/proc 隐藏进程，无法核验本项目任务进程停机');
        $commands=['ai:task-worker','canvas:subagent-worker','home-agent:worker','micro-video:worker','brand:worker','brand:maintain','virtualpay:maintain','short-drama:episode-worker','short-drama:planning-worker','short-drama:canvas-agent-worker','decoration:agent-worker','crontab'];
        $scripts=['start-ai-task-worker.sh','start-brand-worker.sh','start-short-drama-episode-worker.sh','start-short-drama-planning-worker.sh','start-decoration-agent-worker.sh','start-project-supervisor.sh','start-project-cron.sh'];
        $entries=glob('/proc/[0-9]*',GLOB_ONLYDIR);
        if($entries===false)throw new RuntimeException('无法枚举 Linux /proc 项目进程');
        foreach($entries as $process){
            if((int)basename($process)===getmypid())continue;
            $data=@file_get_contents($process.'/cmdline');
            if($data===false){if(!is_dir($process))continue;throw new RuntimeException('进程读取权限不足，无法确认本项目任务进程已排空');}
            if($data==='')continue; // Readable empty cmdline belongs to a kernel thread/zombie.
            $args=array_values(array_filter(explode("\0",$data),fn($v)=>$v!==''));
            foreach($args as $argument){
                if(in_array(basename($argument),$scripts,true)){
                    if(self::resolve($argument,$process)===$root.'/scripts/'.basename($argument))throw new RuntimeException('本项目启动脚本仍运行，请停止本项目 Supervisor 和 cron 后重试');
                }
            }
            if(!array_intersect($commands,$args))continue;
            foreach($args as $argument)if(basename($argument)==='think'){
                if(self::resolve($argument,$process)===$root.'/think')throw new RuntimeException('本项目任务或 cron 进程仍运行，请等待当前任务结束并停止本项目 Supervisor 和 cron 后重试');
            }
        }
    }

    private static function resolve(string $argument,string $process): string
    {
        if(str_starts_with($argument,'/'))return realpath($argument)?:$argument;
        $cwd=@readlink($process.'/cwd');
        if($cwd===false){if(!is_dir($process))return '';throw new RuntimeException('无法解析任务进程的工作目录，升级已阻断');}
        return realpath($cwd.'/'.$argument)?:$cwd.'/'.$argument;
    }
}
