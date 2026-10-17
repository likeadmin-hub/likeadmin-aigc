<?php
namespace app\common\service\app\aigc_canvas;

use InvalidArgumentException;

/** Atomic JSON-only source store. The HTTP service supplies the fixed catalog directory. */
final class CanvasBuiltinPresetSourceStore
{
    public function __construct(private string $directory,private string $journal) {}

    public function assertWritable(): void
    {
        if (!is_dir($this->directory) || is_link($this->directory) || !is_writable($this->directory) || !(fileperms($this->directory)&0222)) throw new InvalidArgumentException('内置预设源码目录不可写，请由安装维护者检查目录权限');
    }

    private function path(string $id): string
    {
        if (!preg_match('/^builtin-[a-z0-9_-]{1,56}$/D',$id)) throw new InvalidArgumentException('内置预设标识无效');
        $path=$this->directory.'/'.$id.'.json';
        if (is_link($path) || (file_exists($path) && (!is_file($path) || !is_writable($path) || !(fileperms($path)&0222)))) throw new InvalidArgumentException('内置预设文件不可替换，请检查文件权限');
        return $path;
    }

    private function version(string $id): int
    {
        $path=$this->path($id);
        if (!file_exists($path)) return 0;
        try { $entry=CanvasBuiltinPresetService::validate(json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR)); }
        catch (\Throwable $e) { throw new InvalidArgumentException('现有内置预设文件无效，未进行替换',0,$e); }
        if ($entry['id']!==$id) throw new InvalidArgumentException('内置预设文件名与标识不一致，未进行替换');
        return $entry['version'];
    }

    private function atomic(string $path,string $content,int $mode): void
    {
        $temporary=tempnam(dirname($path),'.canvas-preset-');
        if ($temporary===false) throw new InvalidArgumentException('无法创建内置预设临时文件');
        try {
            $stream=fopen($temporary,'wb');
            if (!$stream) throw new InvalidArgumentException('无法写入内置预设临时文件');
            try {
                if (fwrite($stream,$content)!==strlen($content) || !fflush($stream)) throw new InvalidArgumentException('内置预设写入未完成，原文件已保留');
                if (function_exists('fsync') && !fsync($stream)) throw new InvalidArgumentException('内置预设保存失败，原文件已保留');
            } finally { fclose($stream); }
            if (!chmod($temporary,$mode) || !rename($temporary,$path)) throw new InvalidArgumentException('内置预设替换失败，原文件已保留');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }

    /** Serializes authoring and stores a receipt before replacing source, so retries do not add versions. */
    public function save(string $requestKey,string $fingerprint,string $target,string $id,int $expectedVersion,callable $build): array
    {
        $this->assertWritable();
        if (!preg_match('/^[a-f0-9]{64}$/D',$requestKey) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) throw new InvalidArgumentException('保存请求标识无效');
        if (!in_array($target,['new','update'],true) || ($target==='update' && $expectedVersion<1)) throw new InvalidArgumentException('请选择保存方式，并刷新要更新的预设版本');
        if (!is_dir($this->journal) && !mkdir($this->journal,0770,true) && !is_dir($this->journal)) throw new InvalidArgumentException('无法创建源码保存记录目录');
        $lockPath=$this->journal.'/source.lock';$receiptPath=$this->journal.'/'.$requestKey.'.json';
        if (is_link($this->journal) || is_link($lockPath) || is_link($receiptPath)) throw new InvalidArgumentException('源码保存记录目录无效');
        $lock=fopen($lockPath,'c');
        if (!$lock) throw new InvalidArgumentException('无法锁定内置预设目录');
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) throw new InvalidArgumentException('另一份内置预设正在保存，请稍后重试');
            $receipt=null;
            if (is_file($receiptPath)) {
                $receipt=json_decode(file_get_contents($receiptPath),true,512,JSON_THROW_ON_ERROR);
                if (($receipt['fingerprint'] ?? '')!==$fingerprint) throw new InvalidArgumentException('保存请求的内容已改变，请重新打开制作窗口');
                $result=$receipt['result'];$path=$this->path($result['preset']['id']);
                if (is_file($path) && hash_equals($receipt['checksum'],hash_file('sha256',$path))) return $result;
                if (!empty($receipt['committed'])) throw new InvalidArgumentException('这份源码文件已被更新，请刷新列表后重新保存');
            }
            if ($target==='update' && $this->version($id)!==$expectedVersion) throw new InvalidArgumentException('内置预设版本已改变，请刷新列表后重新保存');
            $result=$receipt['result'] ?? $build();
            $preset=CanvasBuiltinPresetService::validate($result['preset']);
            if (($target==='update' && ($preset['id']!==$id || $preset['version']!==$expectedVersion+1)) || ($target==='new' && $preset['version']!==1)) throw new InvalidArgumentException('内置预设版本已改变，请刷新列表后重新保存');
            $path=$this->path($preset['id']);
            if ($target==='update' && $this->version($id)!==$expectedVersion) throw new InvalidArgumentException('内置预设版本已改变，请刷新列表后重新保存');
            if ($target==='new' && file_exists($path)) throw new InvalidArgumentException('新预设标识已存在，未替换现有文件');
            $content=json_encode($preset,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            $result=array_intersect_key($result,array_flip(['media_count']))+['filename'=>$preset['id'].'.json','preset'=>$preset,'saved_to_source'=>true,'source_path'=>'server/app/apps/aigc_canvas/data/presets/'.$preset['id'].'.json'];
            $record=['fingerprint'=>$fingerprint,'checksum'=>hash('sha256',$content),'committed'=>false,'result'=>$result];
            // A durable write-ahead record also recovers a request interrupted just before rename.
            $this->atomic($receiptPath,json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),0600);
            $this->atomic($path,$content,0644);
            $record['committed']=true;
            try { $this->atomic($receiptPath,json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),0600); }
            catch (\Throwable $e) { /* The pending receipt still proves a successful source checksum on retry. */ }
            return $result;
        } catch (InvalidArgumentException $e) { throw $e; }
        catch (\Throwable $e) { throw new InvalidArgumentException('内置预设保存失败，请检查源码目录权限后重试',0,$e); }
        finally { flock($lock,LOCK_UN);fclose($lock); }
    }
}
