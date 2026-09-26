<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** Public narration only. Cumulative snapshots make durable replay idempotent.
 * Structured workflow artifacts remain private until final validation/write. */
final class ConversationReplyStream
{
    private string $raw='';
    private string $published='';
    private float $lastText=-1;
    private float $lastHeartbeat=-1;
    private $publish;
    private $clock;
    private float $started;

    public function __construct(callable $publish,private bool $structured,private bool $textEnabled=true,?callable $clock=null)
    {
        $this->publish=$publish;
        $this->clock=$clock??static fn(): float=>microtime(true);
        $this->started=($this->clock)();
    }

    public function receive(string $event,array $data): void
    {
        if (!in_array($event,['delta','heartbeat'],true)) return;
        $now=($this->clock)();
        if ($event==='delta' && is_string($data['delta']??null)) {
            // Bound memory even for a malformed provider. Final validation is
            // still authoritative; never truncate the actual provider result.
            $this->raw=substr($this->raw.$data['delta'],0,400000);
        }
        if ($this->textEnabled && ($this->lastText<0 || $now-$this->lastText>=0.5)) {
            $text=mb_substr(self::visible($this->raw,$this->structured),0,16000,'UTF-8');
            if ($text!=='' && $text!==$this->published) {
                ($this->publish)('reply.progress',['text'=>$text]);
                $this->published=$text;
                $this->lastText=$now;
            }
        }
        if ($this->lastHeartbeat<0 || $now-$this->lastHeartbeat>=5) {
            ($this->publish)('provider.heartbeat',['elapsed_ms'=>max(0,(int)(($now-$this->started)*1000))]);
            $this->lastHeartbeat=$now;
        }
    }

    public static function visible(string $raw,bool $structured): string
    {
        $raw=ltrim($raw);
        if (str_starts_with($raw,'```')) {
            if (!preg_match('/^```(?:json)?\s*\n/i',$raw,$match)) return '';
            $raw=substr($raw,strlen($match[0]));
        }
        if (str_starts_with($raw,'{')) {
            // Tokenize strings (including incomplete strings) rather than a
            // regex search: a nested node/prompt must never become narration.
            $depth=0; $key=null; $expectKey=false;
            for ($i=0,$n=strlen($raw);$i<$n;$i++) {
                $char=$raw[$i];
                if ($char==='{' || $char==='[') { $depth++; if ($depth===1) $expectKey=true; continue; }
                if ($char==='}' || $char===']') { $depth--; continue; }
                if ($char===',' && $depth===1) { $expectKey=true; $key=null; continue; }
                if ($char!=='"') continue;
                $start=++$i;
                while ($i<$n) {
                    if ($raw[$i]==='\\') { $i+=2; continue; }
                    if ($raw[$i]==='"') break;
                    $i++;
                }
                $encoded=substr($raw,$start,min($i,$n)-$start);
                if ($depth!==1) continue;
                if ($expectKey) {
                    if ($i>=$n) return '';
                    $key=json_decode('"'.$encoded.'"',true);
                    $expectKey=false;
                } elseif ($key==='reply_markdown') {
                    // An SSE frame can split an escape, a UTF-8 character or
                    // a UTF-16 surrogate pair. Retain only a decodable prefix.
                    for ($tail=0;$tail<=12 && $tail<=strlen($encoded);$tail++) {
                        $value=json_decode('"'.substr($encoded,0,strlen($encoded)-$tail).'"',true);
                        if (is_string($value)) return self::publicText($value);
                    }
                    return '';
                }
            }
            return '';
        }
        if ($structured || str_starts_with($raw,'[')) return '';
        return mb_check_encoding($raw,'UTF-8') ? self::publicText($raw) : '';
    }

    private static function publicText(string $text): string
    {
        // Hold markup as soon as '<' arrives, including a split canvas-actions
        // tag. No action plan or reasoning tag can flash into the conversation.
        $offset=strpos($text,'<');
        return ltrim($offset===false ? $text : substr($text,0,$offset));
    }
}
