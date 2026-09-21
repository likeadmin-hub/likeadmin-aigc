<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Inline text is user-supplied material, never a storage path or tool authority. */
final class ConversationAttachments
{
    public static function normalize(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items)>10) throw new RuntimeException('INVALID_ATTACHMENTS');
        $result=[];$bytes=0;
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item),['type','name','content']) || ($item['type']??null)!=='text') throw new RuntimeException('INVALID_ATTACHMENTS');
            $name=$item['name']??null;$content=$item['content']??null;
            if (!is_string($name) || !mb_check_encoding($name,'UTF-8') || mb_strlen($name)>160 || !preg_match('/\.(txt|md|markdown)$/iu',$name) || preg_match('/[\x00-\x1f\/\\\\]/u',$name)) throw new RuntimeException('INVALID_ATTACHMENTS');
            if (!is_string($content) || !mb_check_encoding($content,'UTF-8') || trim($content)==='' || str_contains($content,"\0") || strlen($content)>102400) throw new RuntimeException('INVALID_ATTACHMENTS');
            $bytes+=strlen($content);
            if ($bytes>409600) throw new RuntimeException('CONTEXT_TOO_LARGE');
            $result[]=['type'=>'text','name'=>$name,'content'=>$content];
        }
        return $result;
    }
}
