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
            if (!is_array($item) || !is_string($item['type']??null)) throw new RuntimeException('INVALID_ATTACHMENTS');
            if ($item['type']==='image') {
                if (array_diff(array_keys($item),['type','asset_id','name']) || !is_int($item['asset_id']??null) || $item['asset_id']<=0 || !is_string($item['name']??null) || mb_strlen($item['name'])>120) throw new RuntimeException('INVALID_ATTACHMENTS');
                $result[]=['type'=>'image','asset_id'=>$item['asset_id'],'name'=>$item['name']];
                continue;
            }
            if (array_diff(array_keys($item),['type','name','content']) || $item['type']!=='text') throw new RuntimeException('INVALID_ATTACHMENTS');
            $name=$item['name']??null;$content=$item['content']??null;
            if (!is_string($name) || !mb_check_encoding($name,'UTF-8') || mb_strlen($name)>160 || !preg_match('/\.(txt|md|markdown)$/iu',$name) || preg_match('/[\x00-\x1f\/\\\\]/u',$name)) throw new RuntimeException('INVALID_ATTACHMENTS');
            if (!is_string($content) || !mb_check_encoding($content,'UTF-8') || trim($content)==='' || str_contains($content,"\0") || strlen($content)>102400) throw new RuntimeException('INVALID_ATTACHMENTS');
            $bytes+=strlen($content);
            if ($bytes>409600) throw new RuntimeException('CONTEXT_TOO_LARGE');
            $result[]=['type'=>'text','name'=>$name,'content'=>$content];
        }
        return $result;
    }

    /** Browser-visible message data intentionally omits storage identity. */
    public static function public(array $items): array
    {
        return array_map(static fn(array $item)=>$item['type']==='image'
            ? ['type'=>'image','asset_id'=>$item['asset_id'],'name'=>$item['name']]
            : $item, $items);
    }
}
