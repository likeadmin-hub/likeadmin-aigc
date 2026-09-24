<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/**
 * Resolves only a small, deterministic subset of positional references.
 * A vague image reference never becomes an implicit Provider input: when two
 * image nodes occupy the same leading position band, the caller must surface
 * these safe node labels and wait for an explicit canvas selection.
 */
final class ConversationReferenceResolver
{
    /** @return list<array{node_id:string,title:string,type:string}> */
    public static function ambiguousImageCandidates(array $nodes,array $explicitNodeIds,string $content): array
    {
        if ($explicitNodeIds || !self::side($content)) return [];
        $images=[];
        foreach ($nodes as $node) {
            if (!is_array($node) || ($node['type']??'')!=='image' || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)($node['id']??''))) continue;
            $images[]=[
                'node_id'=>(string)$node['id'],
                'title'=>self::title($node),
                'type'=>'image',
                'x'=>self::number($node['x']??0),
                'width'=>max(1,self::number($node['width']??240)),
            ];
        }
        if (count($images)<2) return [];
        $left=self::side($content)==='left';
        usort($images,static fn(array $a,array $b): int => $left ? ($a['x']<=>$b['x']) : ($b['x']<=>$a['x']));
        $first=$images[0]; $threshold=max(48,min(320,(int)floor(max($first['width'],$images[1]['width']) / 2)));
        if (abs($first['x']-$images[1]['x'])>$threshold) return [];
        $candidates=[];
        foreach ($images as $image) {
            if (abs($first['x']-$image['x'])>$threshold || count($candidates)>=4) break;
            $candidates[]=['node_id'=>$image['node_id'],'title'=>$image['title'],'type'=>'image'];
        }
        return count($candidates)>1 ? $candidates : [];
    }

    /** @return list<array{node_id:string,title:string,type:string}> */
    public static function publicCandidates(mixed $candidates): array
    {
        if (!is_array($candidates) || !array_is_list($candidates) || count($candidates)<2 || count($candidates)>4) return [];
        $result=[];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)($candidate['node_id']??'')) || ($candidate['type']??'')!=='image' || !is_string($candidate['title']??null)) return [];
            $title=trim($candidate['title']);
            if ($title==='' || mb_strlen($title)>80) return [];
            $result[]=['node_id'=>(string)$candidate['node_id'],'title'=>$title,'type'=>'image'];
        }
        return count(array_unique(array_column($result,'node_id')))===count($result) ? $result : [];
    }

    private static function side(string $content): string
    {
        if (!preg_match('/(?:图|图片|image)/iu',$content)) return '';
        if (preg_match('/(?:左边|左侧|左面|left)/iu',$content)) return 'left';
        if (preg_match('/(?:右边|右侧|右面|right)/iu',$content)) return 'right';
        return '';
    }

    private static function title(array $node): string
    {
        $title=trim(preg_replace('/\s+/u',' ',strip_tags((string)($node['title']??'')))??'');
        return mb_substr($title!==''?$title:'图片节点 #'.(string)$node['id'],0,80);
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) && is_finite((float)$value) ? (int)round((float)$value) : 0;
    }
}
