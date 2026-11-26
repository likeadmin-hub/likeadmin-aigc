<?php
namespace app\common\service\app\aigc_depth_replication;

use app\common\service\ai\StudioMediaInputService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\MediaBinaryService;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;

final class DepthWorker
{
    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \FilesystemIterator($dir) as $file) {
            if ($file->isDir() && !$file->isLink()) self::remove($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public static function tick(): bool
    {
        $root = dirname(DepthJobs::folder(0));
        if (!is_dir($root) && !mkdir($root,0770,true) && !is_dir($root)) throw new RuntimeException('深度缓存目录不可写');
        $lock = fopen($root.'/worker.lock','c');
        if (!$lock) throw new RuntimeException('深度任务锁不可用');
        try {
            // The queue and its private cache share the same volume. A crashed worker releases this lock.
            if (!flock($lock,LOCK_EX|LOCK_NB)) return false;
            $row = Db::name(DepthJobs::TABLE)->whereIn('status',['queued','running'])->order('id')->find();
            if (!$row) {
                if (!file_exists($root.'/swept') || filemtime($root.'/swept') < time()-3600) {
                    foreach (glob($root.'/[0-9]*',GLOB_ONLYDIR) ?: [] as $dir) {
                        if (filemtime($dir)<time()-7*86400) self::remove($dir);
                    }
                    touch($root.'/swept');
                }
                return false;
            }
            $query = fn()=>Db::name(DepthJobs::TABLE)->where('id',$row['id']);
            $query()->update(['status'=>'running','update_time'=>time()]);
            try {
                $result = self::process($row);
                $query()->update(['status'=>'success','result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'update_time'=>time()]);
            } catch (\Throwable $e) {
                Log::error('Depth job '.$row['id'].': '.$e->getMessage());
                $query()->update(['status'=>'failed','error'=>$e instanceof \InvalidArgumentException ? mb_substr($e->getMessage(),0,240) : '处理失败，请检查视频或联系管理员查看任务日志','update_time'=>time()]);
            }
            return true;
        } finally { fclose($lock); }
    }

    private static function process(array $row): array
    {
        $home = getenv('DEPTH_MODEL_HOME') ?: '/opt/likeadmin-depth';
        $python = getenv('DEPTH_PYTHON') ?: $home.'/venv/bin/python';
        if (!is_executable($python) || !is_readable($home.'/small.pth')) throw new \InvalidArgumentException('深度模型服务尚未安装，请联系管理员');
        $folder = DepthJobs::folder((int)$row['id']);
        if (!is_dir($folder)) mkdir($folder,0770,true);
        $work = $folder.'/attempt-'.bin2hex(random_bytes(8));
        mkdir($work,0770);
        $asset = json_decode($row['source_json'],true);
        $tenant = (int)$row['tenant_id']; $user = (int)$row['user_id'];
        try {
            $script = base_path().'apps/'.DepthJobs::APP.'/worker/pipeline.py';
            if ($row['kind']==='analyze') {
                $cache = $folder.'/cache';
                if (!is_file($cache.'/index.json')) {
                    StudioMediaInputService::copyOwnedVideo($tenant,$user,$asset['uri'],$work.'/source');
                    self::run([$python,$script,'analyze',$work.'/source',$work],$work);
                    if (!rename($work,$cache)) throw new RuntimeException('深度缓存保存失败');
                }
                $index = json_decode(file_get_contents($cache.'/index.json'),true,512,JSON_THROW_ON_ERROR);
                return ['analyze_seconds'=>$index['analyze_seconds'],'frames'=>count($index['pts'])];
            }
            $parent = DepthJobs::analysisFor($row);
            DepthJobs::index($parent);
            $cache = DepthJobs::folder((int)$parent['id']).'/cache';
            $settings = json_decode($row['settings_json'],true);
            $tables = ['all'=>Lighting::table($settings['global'])];
            foreach ($settings['shots'] as $shot=>$overrides) $tables[$shot] = Lighting::table($overrides + $settings['global']);
            file_put_contents($work.'/lut.json', json_encode($tables,JSON_THROW_ON_ERROR));
            $out = $work.'/result.mp4';
            $report = self::run([$python,$script,'render',$cache.'/source',$cache,'--tables',$work.'/lut.json','--output',$out],$work);
            $result = json_decode($report,true,512,JSON_THROW_ON_ERROR);
            $result['asset'] = AigcVideoAssetService::persistGeneratedVideoFile($out,$tenant,$user);
            return $result;
        } finally { self::remove($work); }
    }

    private static function run(array $args, string $dir): string
    {
        $env = getenv();
        foreach (['ffmpeg','ffprobe'] as $tool) {
            $path = MediaBinaryService::resolve($tool);
            if (!$path) throw new \InvalidArgumentException('服务器缺少视频编解码工具');
            $env[strtoupper($tool).'_BINARY'] = $path;
        }
        $process = proc_open($args,[0=>['file','/dev/null','r'],1=>['file',$dir.'/report','w'],2=>['file',$dir.'/log','w']],$pipes,null,$env);
        if (!is_resource($process)) throw new RuntimeException('无法启动深度处理');
        $deadline = time()+900;
        do {
            usleep(200000);
            $state = proc_get_status($process);
            if (!$state['running']) break;
            if (time() > $deadline) { proc_terminate($process,9); break; }
        } while (true);
        $closed = proc_close($process);
        if (($state['exitcode'] < 0 ? $closed : $state['exitcode']) !== 0) {
            $log = substr((string)file_get_contents($dir.'/log'),-2000);
            if (preg_match('/ValueError: (仅支持[^\n]+|视频时间轴[^\n]+|导出音视频时间轴校验失败)/u',$log,$m)) throw new \InvalidArgumentException($m[1]);
            throw new RuntimeException($log ?: '深度处理超时或中止');
        }
        return (string)file_get_contents($dir.'/report');
    }
}
