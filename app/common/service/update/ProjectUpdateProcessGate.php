<?php
namespace app\common\service\update;

use RuntimeException;

/** Read-only Linux process gate. Never signals processes or prints their arguments. */
class ProjectUpdateProcessGate
{
    public static function assertDrained(string $siteRoot): void
    {
        $root=realpath($siteRoot);
        if($root===false || PHP_OS_FAMILY!=='Linux' || !is_readable('/proc/mounts'))throw new RuntimeException('无法核验本项目任务进程；需可读取 Linux /proc，平滑停止本项目 Supervisor 和 cron 后重试');
        if(preg_match('/(?:^|,)hidepid=(?:1|2|4)(?:,|\s)/m',(string)file_get_contents('/proc/mounts')))throw new RuntimeException('/proc 隐藏进程，无法核验本项目任务进程停机');
        $commands=['ai:task-worker','canvas:subagent-worker','short-drama:episode-worker','short-drama:planning-worker','short-drama:canvas-agent-worker','crontab'];
        $scripts=['start-ai-task-worker.sh','start-short-drama-episode-worker.sh','start-short-drama-planning-worker.sh','start-project-cron.sh'];
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
