<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Read-only subscription to worker output. Reconnection sends the complete durable state. */
final class AgentStreamService
{
    public static function follow(int $tenant,int $user,int $id,callable $emit,callable $connected,float $seconds=20): void
    {
        $deadline=microtime(true)+min(20,max(0,$seconds));$signature='';$heartbeat=0.0;
        do {
            if(!$connected())return;
            $run=AgentStore::run($tenant,$user,$id);
            $cursor=(int)AgentStore::owner('event',$tenant,$user)->where('run_id',$id)->max('id');
            // Include the durable row: updates can precede their event or have no event.
            $next=hash('sha256',AgentStore::encode([$run['reply'],$run['preferences'],$run['status'],$run['phase'],$run['cancel_requested'],$run['error'],$cursor]));
            $now=microtime(true);
            if($signature!==$next||$now-$heartbeat>=5){
                $detail=AgentExecutionService::detail($tenant,$user,$id);
                $emit('run.snapshot',['run'=>$detail]);
                $signature=$next;$heartbeat=$now;
                if(!in_array($detail['status'],['queued','running','waiting_tasks'],true)){
                    $emit('done',['run_id'=>$id]);return;
                }
            }
            usleep($run['phase']==='chat'?100000:500000);
        } while(microtime(true)<$deadline);
        $emit('reconnect',['run_id'=>$id]);
    }
}
