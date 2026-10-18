<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Lossless structural boundaries. Character cursors always refer to the unchanged extracted text. */
final class AgentTextSegmenter
{
    public static function page(string $text,int $offset,int $limit=4000,string $format='text'): array
    {
        $offset=max(0,$offset);$limit=max(1,$limit);$total=mb_strlen($text);
        $length=min($limit,max(0,$total-$offset));$boundary='eof';
        if($offset+$length<$total){
            $part=mb_substr($text,$offset,$length);$minimum=(int)ceil($limit*0.75);
            $boundary='hard_limit';$breaks=[];
            if($format==='csv'){
                // CSV record boundaries exclude embedded newlines and escaped quotes in quoted fields.
                $prefix=mb_substr($text,0,$offset+$length);$quoted=false;$chars=0;
                for($i=0,$bytes=strlen($prefix);$i<$bytes;$i++){
                    $c=$prefix[$i];
                    if((ord($c)&0xC0)!==0x80)$chars++;
                    if($c==='"'){
                        if($quoted&&($prefix[$i+1]??'')==='"'){$i++;$chars++;}else $quoted=!$quoted;
                    }
                    if($c==="\n"&&!$quoted&&$chars-$offset>=$minimum)$breaks[]=$chars-$offset;
                }
                if($breaks){$length=max($breaks);$boundary='record';}
            }else{
                foreach(['paragraph'=>'/\n[\t ]*\n/u','line'=>'/\n/u','sentence'=>'/[。！？]|[.!?](?=\s)/u'] as $kind=>$pattern){
                    preg_match_all($pattern,$part,$matches,PREG_OFFSET_CAPTURE);
                    foreach($matches[0] as [$match,$byte]){
                        $end=mb_strlen(substr($part,0,$byte+strlen($match)));
                        if($end<$minimum)continue;
                        // Do not leave a Markdown heading on the preceding page without its body.
                        $before=rtrim(mb_substr($part,0,$end),"\n\r\t ");$newline=mb_strrpos($before,"\n");$lastLine=mb_substr($before,$newline===false?0:$newline+1);
                        if(preg_match('/^\s*#{1,6}\s+\S/u',$lastLine))continue;
                        $following=mb_substr($text,$offset+$end,300);
                        if(preg_match('/^\s*\|?\s*:?-{3,}[^\n]*\|/u',$lastLine)||preg_match('/^\s*\|?\s*:?-{3,}[^\n]*\|/u',$following))continue;
                        $breaks[]=$end;
                    }
                    if($breaks){$length=max($breaks);$boundary=$kind;break;}
                    // Tabular rows without a safe line boundary must not split at cell punctuation.
                    if($kind==='line'&&(str_contains($part,"\t")||preg_match('/^\s*\|/m',$part)))break;
                }
            }
        }
        return ['content'=>mb_substr($text,$offset,$length),'offset'=>$offset,'total_chars'=>$total,
            'next_offset'=>$offset+$length<$total?$offset+$length:null,'boundary'=>$boundary,'forced_split'=>$boundary==='hard_limit'];
    }
}
