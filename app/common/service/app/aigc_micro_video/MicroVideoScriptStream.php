<?php
namespace app\common\service\app\aigc_micro_video;

/** Only projectable script prose; never expose the model's JSON or reasoning. */
final class MicroVideoScriptStream
{
    public static function preview(string $raw,array $context): string
    {
        if(!preg_match('/^\s*(?:```json\s*)?\{\s*"script"\s*:\s*\{\s*"text"\s*:\s*"/u',$raw,$match))return '';
        $value='';$escaped=false;
        for($i=strlen($match[0]),$length=strlen($raw);$i<$length;$i++){
            $char=$raw[$i];if(!$escaped&&$char==='"')break;
            $value.=$char;if($escaped)$escaped=false;elseif($char==='\\')$escaped=true;
        }
        // A transport chunk may end inside a JSON escape or Unicode surrogate pair.
        $text=null;
        for($cut=0;$cut<=12&&$cut<=strlen($value);$cut++){
            $text=json_decode('"'.($cut?substr($value,0,-$cut):$value).'"',true);
            if(is_string($text))break;
        }
        if(!is_string($text))return '';
        return MicroVideoWorkbenchRecords::streamProse(mb_substr($text,0,60000),$context);
    }
}
