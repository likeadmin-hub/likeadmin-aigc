<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Private local process heartbeats. Contains no prompts, media, keys or user data. */
final class AgentWorkerHealth
{
    private string $file;
    private int $started;
    public function __construct(?string $directory=null)
    {
        $directory??=runtime_path().'home-agent-workers';
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('无法创建 Agent 心跳目录');
        $this->file=$directory.'/'.hash('sha256',gethostname()?:'local').'-'.getmypid().'.json';
        $this->started=time();
        // Remove abandoned metadata only after a day. Never touches task/receipt files.
        foreach(glob($directory.'/*.json')?:[] as $path)if(filemtime($path)<time()-86400)@unlink($path);
        $this->beat('starting');
    }
    public function beat(string $state): void
    {
        $row=['pid'=>getmypid(),'host'=>gethostname()?:'local','started_at'=>$this->started,'updated_at'=>time(),'state'=>$state];
        $tmp=$this->file.'.tmp';
        if(file_put_contents($tmp,json_encode($row,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('Agent 心跳写入失败');
        chmod($tmp,0600);
        if(!rename($tmp,$this->file))throw new \RuntimeException('Agent 心跳保存失败');
    }
    public function close(): void {if(is_file($this->file))unlink($this->file);}
    public static function processes(?string $directory=null): array
    {
        $rows=[];
        foreach(glob(($directory??runtime_path().'home-agent-workers').'/*.json')?:[] as $file){
            $row=json_decode((string)@file_get_contents($file),true);
            if(!is_array($row)||($row['host']??'')!==(gethostname()?:'local'))continue;
            $age=max(0,time()-(int)($row['updated_at']??0));
            $pid=(int)($row['pid']??0);
            $alive=$pid>0&&function_exists('posix_kill')?@posix_kill($pid,0):null;
            // A synchronous turn may hold its lease for 1500 seconds. Idle workers heartbeat every second.
            $fresh=$age<=(($row['state']??'')==='busy'?1560:30);
            $rows[]=['pid'=>$pid,'state'=>$row['state']??'unknown','age_seconds'=>$age,'alive'=>$alive,'healthy'=>$alive!==false&&$fresh];
        }
        return $rows;
    }
    public static function report(): array
    {
        $processes=self::processes();$now=time();
        $runs=AgentStore::table('run');
        $oldest=(int)(clone $runs)->whereIn('status',['queued','waiting_tasks'])->where('next_run_time','<=',$now)->min('create_time');
        $review=\think\facade\Db::name('ai_consumption_log')->where(['app_code'=>'aigc_llm','action_code'=>'agent_transcribe','billing_status'=>'reserved','run_status'=>'needs_review']);
        $speech=\think\facade\Db::name('ai_consumption_log')->where(['app_code'=>'aigc_llm','action_code'=>'agent_speech','billing_status'=>'reserved','run_status'=>'needs_review']);
        $media=['proc_open'=>function_exists('proc_open')];
        foreach(['pdfinfo','pdftotext','pdftoppm','ffmpeg','ffprobe'] as $bin)$media[$bin]=is_executable('/usr/bin/'.$bin);
        $journal=runtime_path().'home-agent-stt-receipts';
        return ['healthy'=>count(array_filter($processes,static fn($p)=>$p['healthy']))>0,
            'processes'=>$processes,'media_dependencies'=>$media,
            'receipt_storage_writable'=>is_dir($journal)?is_writable($journal):is_writable(runtime_path()),
            'queue'=>['ready'=>(clone $runs)->whereIn('status',['queued','waiting_tasks'])->where('next_run_time','<=',$now)->count(),
                'running'=>(clone $runs)->where('status','running')->count(),
                'expired_leases'=>(clone $runs)->where('status','running')->where('lease_until','<',$now)->count(),
                'oldest_ready_age_seconds'=>$oldest?$now-$oldest:0,
                'review_runs'=>(clone $runs)->where('status','needs_review')->count()],
            'stt_review'=>['count'=>(clone $review)->count(),'reserved_points'=>(float)(clone $review)->sum('reserved_user_price')],
            'speech_review'=>['count'=>(clone $speech)->count(),'reserved_points'=>(float)(clone $speech)->sum('reserved_user_price')]];
    }
}
