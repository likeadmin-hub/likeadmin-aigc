<?php
namespace app\common\service\power;

/** A native failed event can prove rejection; a disconnect or partial output cannot. */
final class MarketTextFailurePolicy
{
    public static function preGenerationRejection(array $result):bool
    {
        $p=$result['protocol_evidence']??[];$d=$result['protocol_diagnostic']??[];
        if(($p['branch']??'')!=='native_responses'||($p['terminal_seen']??null)!==true||($p['terminal_status']??'')!=='failed'
            ||($p['output_present']??null)!==false||($p['legacy_output_event_count']??0)!==0||trim((string)($result['content']??''))!==''
            ||($d['last_event']??'')!=='response.failed'||($d['response_status']??'')!=='failed'||($d['output_present']??null)!==false
            ||($d['provider_code']??'')!=='INSUFFICIENT_BALANCE'||trim((string)($result['provider_request_id']??''))==='')return false;
        foreach(['output_items','visible_chars','reasoning_chars','delta_count','reasoning_delta_count','function_delta_count']as$key)
            if(!array_key_exists($key,$d)||$d[$key]!==0)return false;
        foreach(['prompt_tokens','completion_tokens','input_tokens','output_tokens','total_tokens']as$key)
            if(isset($result['usage'][$key])&&(!is_numeric($result['usage'][$key])||(float)$result['usage'][$key]!==0.0))return false;
        return true;
    }
}
