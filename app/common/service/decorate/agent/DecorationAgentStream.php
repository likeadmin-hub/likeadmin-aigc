<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** Read-only transport. Injected IO allows deterministic frame and disconnect tests. */
final class DecorationAgentStream
{
    private \Closure $clock;
    private \Closure $sleep;
    private \Closure $disconnected;
    public function __construct(private \Closure $read, private \Closure $write, ?\Closure $clock=null, ?\Closure $sleep=null, ?\Closure $disconnected=null)
    {
        $this->clock=$clock??fn()=>microtime(true);
        $this->sleep=$sleep??fn(int $micros)=>usleep($micros);
        $this->disconnected=$disconnected??fn()=>connection_aborted()!==0;
    }
    public function send(array $initial,int $after=0,float $duration=20): void
    {
        if (($this->disconnected)()) return;
        $start=($this->clock)();$keepalive=$start;$snapshot=$initial;
        $this->frame('run',$snapshot);
        $fingerprint=$this->json($snapshot);
        while (!(($this->disconnected)())) {
            if (in_array($snapshot['status']??'', ['completed','awaiting_input','failed','limited','budget_exceeded','needs_review','canceled'],true)) { $this->frame('done',$snapshot);return; }
            $now=($this->clock)();
            if ($now-$start>=min(20,max(0.1,$duration))) { $this->frame('reconnect',$snapshot);return; }
            if ($now-$keepalive>=4) { ($this->write)(": keepalive\n\n");$keepalive=$now; }
            try { $snapshot=($this->read)(); } catch (\Throwable $e) { $this->frame('error',['message'=>'连接已中断，请重新连接获取最新进度']);return; }
            $next=$this->json($snapshot);
            if ($next!==$fingerprint) { $this->frame('run',$snapshot);$fingerprint=$next; }
            // A changed terminal snapshot should be followed immediately by done.
            if (!in_array($snapshot['status']??'', ['completed','awaiting_input','failed','limited','budget_exceeded','needs_review','canceled'],true)) ($this->sleep)(250000);
        }
    }
    private function json(array $snapshot): string { return json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private function frame(string $event,array $snapshot): void
    {
        $id=0;foreach($snapshot['events']??[] as $entry)$id=max($id,(int)($entry['id']??0));
        // Cumulative text is carried once in assistant_reply; retain the cursor from all durable events.
        if(isset($snapshot['events']))$snapshot['events']=array_values(array_filter($snapshot['events'],fn($entry)=>($entry['type']??'')!=='assistant'));
        ($this->write)("event: $event\n".($id>0?"id: $id\n":'').'data: '.$this->json($snapshot)."\n\n");
    }
}
