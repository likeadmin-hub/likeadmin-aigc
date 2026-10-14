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
        // A long supplier task is not a stalled queue: measure from its next due poll.
        $due=(clone $runs)->whereIn('status',['queued','waiting_tasks'])->where('next_run_time','<=',$now);
        $times=array_filter([(int)(clone $due)->where('next_run_time','>',0)->min('next_run_time'),
            (int)(clone $due)->where('next_run_time','<=',0)->min('create_time')]);
        $oldest=$times?min($times):0;
        $expired=(clone $runs)->where('status','running')->where('lease_until','<',$now);
        $expiredCount=(clone $expired)->count();
        $oldestLease=(int)(clone $expired)->min('lease_until');
        $review=\think\facade\Db::name('ai_consumption_log')->where(['app_code'=>'aigc_llm','action_code'=>'agent_transcribe','billing_status'=>'reserved','run_status'=>'needs_review']);
        $speech=\think\facade\Db::name('ai_consumption_log')->where(['app_code'=>'aigc_llm','action_code'=>'agent_speech','billing_status'=>'reserved','run_status'=>'needs_review']);
        $media=['proc_open'=>function_exists('proc_open')];
        foreach(['pdfinfo','pdftotext','pdftoppm','ffmpeg','ffprobe'] as $bin)$media[$bin]=is_executable('/usr/bin/'.$bin);
        $storage=[];
        foreach(['stt','speech'] as $kind){
            $journal=runtime_path().'home-agent-'.$kind.'-receipts';
            $storage[$kind]=file_exists($journal)?is_dir($journal)&&is_writable($journal):is_writable(runtime_path());
        }
        return self::evaluate(['healthy'=>count(array_filter($processes,static fn($p)=>$p['healthy']))>0,
            'processes'=>$processes,'media_dependencies'=>$media,
            'receipt_storage'=>$storage,'receipt_storage_writable'=>!in_array(false,$storage,true),
            'queue'=>['ready'=>(clone $runs)->whereIn('status',['queued','waiting_tasks'])->where('next_run_time','<=',$now)->count(),
                'running'=>(clone $runs)->where('status','running')->count(),
                'expired_leases'=>$expiredCount,
                'oldest_expired_lease_age_seconds'=>$expiredCount?$now-$oldestLease:0,
                'oldest_ready_age_seconds'=>$oldest?$now-$oldest:0,
                'review_runs'=>(clone $runs)->where('status','needs_review')->count()],
            'stt_review'=>['count'=>(clone $review)->count(),'reserved_points'=>(float)(clone $review)->sum('reserved_user_price')],
            'speech_review'=>['count'=>(clone $speech)->count(),'reserved_points'=>(float)(clone $speech)->sum('reserved_user_price')]]);
    }
    /** Stable, content-free signals for an external scheduler/monitor. Never repairs tasks. */
    public static function evaluate(array $report): array
    {
        $issues=[];
        $add=static function(string $code,string $severity)use(&$issues){$issues[]=['code'=>$code,'severity'=>$severity];};
        if(!count(array_filter($report['processes'],static fn($p)=>$p['healthy'])))$add('worker_unavailable','critical');
        if(in_array(false,$report['media_dependencies'],true))$add('media_dependency_unavailable','critical');
        if(!$report['receipt_storage_writable'])$add('receipt_storage_unwritable','critical');
        if($report['queue']['oldest_ready_age_seconds']>=300)$add('queue_stalled','critical');
        // Allow the worker one recovery cycle before alerting on a just-expired lease.
        if($report['queue']['expired_leases']>0&&$report['queue']['oldest_expired_lease_age_seconds']>=60)$add('lease_recovery_stalled','critical');
        if($report['queue']['review_runs']>0||$report['stt_review']['count']>0||$report['speech_review']['count']>0)$add('manual_review_required','warning');
        $report['healthy']=!count(array_filter($issues,static fn($issue)=>$issue['severity']==='critical'));
        $report['attention_required']=(bool)$issues;
        $report['issues']=$issues;
        return $report;
    }
}
