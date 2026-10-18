<?php
namespace app\common\service\app\aigc_canvas;

use InvalidArgumentException;

/** The media portion of a template; tasks, credentials and source ownership never travel with it. */
final class CanvasTemplateMedia
{
    public static function capture(string $type, array $input, callable $copy): array
    {
        $result = [];
        if (in_array($type, ['image','video','audio'], true)) {
            $items = array_key_exists('mediaItems', $input) ? $input['mediaItems'] : [array_merge($input, ['url'=>self::url($input, $type)])];
            if (!is_array($items) || count($items)>32) throw new InvalidArgumentException('单个节点最多保留32份媒体素材');
            $media = [];
            foreach ($items as $i=>$item) {
                if (!is_array($item)) continue;
                $url = self::url($item, $type);
                if ($url==='') continue;
                $file = $copy($url, $type, $item);
                $source=$item['mediaSource'] ?? $input['mediaSource'] ?? $input['source'] ?? '';
                $generated=in_array($source,['generated','agent'],true) || ($source==='' && (!empty($input['taskId']) || (!empty($input['prompt']) && (!empty($input['channel']) || !empty($input['model'])))));
                $entry = ['id'=>(string)($item['id'] ?? 'media-'.$i), 'type'=>$type, 'url'=>$file['url'], 'template_file'=>$file['file'], 'mediaSource'=>$generated?'generated':'upload'];
                foreach (['width','height','duration','fileSize','mimeType','fileName'] as $key) if (isset($file[$key])) $entry[$key]=$file[$key];
                $generation=CanvasMediaPreviewService::sanitize((array)($item['generation'] ?? []));
                if ($generation) {
                    foreach ($generation['references'] as &$ref) {
                        $r=$copy($ref['url'],$ref['type'],$ref);
                        $ref['url']=$r['url'];$ref['template_file']=$r['file'];
                    }
                    unset($ref);
                    $entry['generation']=$generation;
                }
                $poster = self::first($item, $type==='video'?['poster','poster_url','cover_url','cover','thumbnail','image']:['poster','poster_url','cover_url','cover','thumbnail']);
                if ($poster!=='' && $type!=='image') { $p=$copy($poster,'image',[]); $entry['poster']=$p['url']; $entry['template_poster']=$p['file']; }
                $media[]=$entry;
            }
            if ($media) {
                $active = array_search($input['activeMediaId'] ?? '',array_column($media,'id'),true);
                $selected = $media[$active===false?0:$active];
                $result = ['mediaItems'=>$media,'activeMediaId'=>$selected['id'],'url'=>$selected['url'],'mediaSource'=>$selected['mediaSource'],'source'=>$selected['mediaSource'],'status'=>'success'];
                if ($type==='image') $result['image']=$selected['url'];
                if (isset($selected['poster'])) $result['poster']=$selected['poster'];
            } elseif (array_key_exists('mediaItems',$input)) $result['mediaItems']=[];
        }
        foreach (['image'=>'referenceImages','video'=>'referenceVideos','audio'=>'referenceAudios'] as $kind=>$field) {
            $refs=$input[$field] ?? [];
            if (!is_array($refs) || count($refs)>32) throw new InvalidArgumentException('单个节点参考素材最多32份');
            foreach ($refs as $ref) {
                if (!is_array($ref) || self::url($ref,$kind)==='') continue;
                $file=$copy(self::url($ref,$kind),$kind,$ref);
                $entry=['url'=>$file['url'],'template_file'=>$file['file'],'name'=>mb_substr((string)($ref['name'] ?? ''),0,120)];
                if (in_array($ref['role'] ?? '',['first_frame_image','last_frame_image','reference_image','subject','style'],true)) $entry['role']=$ref['role'];
                $result[$field][]=$entry;
            }
        }
        foreach ($input['mentions'] ?? [] as $mention) {
            if (!is_array($mention) || ($mention['source'] ?? '')==='node') continue;
            $kind=(string)($mention['type'] ?? '');
            if ($kind==='asset') { $hint=strtolower(($mention['mime_type'] ?? '').' '.($mention['asset_type'] ?? ''));$kind=str_contains($hint,'video')?'video':(str_contains($hint,'audio')?'audio':'image'); }
            if (!in_array($kind,['image','video','audio'],true) || self::url($mention,$kind)==='') continue;
            $file=$copy(self::url($mention,$kind),$kind,$mention);
            $result['mentions'][]=['id'=>'template-'.count($result['mentions'] ?? []),'source'=>'upload','type'=>$kind,'name'=>mb_substr((string)($mention['name'] ?? ''),0,120),'url'=>$file['url'],'template_file'=>$file['file']];
        }
        return $result;
    }

    public static function url(array $row,string $type): string
    {
        return self::first($row,$type==='image'?['image','url','image_url','file_url','assetUri','uri','output_url','download_url']:['url',$type.'_url',$type,'file_url','assetUri','uri','output_url','download_url']);
    }
    private static function first(array $row,array $keys): string
    {
        foreach ($keys as $key) if (is_string($row[$key] ?? null) && trim($row[$key])!=='') return trim($row[$key]);
        return '';
    }

    /** Resolve URLs when used, so a private-storage signature is never the durable identity. */
    public static function hydrate(array $metadata,callable $resolve): array
    {
        foreach (['mediaItems','referenceImages','referenceVideos','referenceAudios','mentions'] as $field) {
            foreach ($metadata[$field] ?? [] as $i=>$item) {
                if (isset($item['template_file'])) $metadata[$field][$i]['url']=$resolve($item['template_file']);
                if (isset($item['template_poster'])) $metadata[$field][$i]['poster']=$resolve($item['template_poster']);
                foreach ($item['generation']['references'] ?? [] as $j=>$ref) {
                    if (isset($ref['template_file'])) $metadata[$field][$i]['generation']['references'][$j]['url']=$resolve($ref['template_file']);
                }
            }
        }
        if (!empty($metadata['mediaItems'])) {
            $index=array_search($metadata['activeMediaId'] ?? '',array_column($metadata['mediaItems'],'id'),true);
            $item=$metadata['mediaItems'][$index===false?0:$index];
            $metadata['url']=$item['url'];
            if ($item['type']==='image') $metadata['image']=$item['url'];
            $metadata['poster']=$item['poster'] ?? '';
        }
        return $metadata;
    }
}
