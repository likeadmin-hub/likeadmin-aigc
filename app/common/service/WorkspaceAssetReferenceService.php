<?php
namespace app\common\service;

use app\common\enum\FileEnum;
use think\facade\Db;

/** Resolve recorded inputs with their own storage metadata, not the current default disk. */
final class WorkspaceAssetReferenceService
{
    private const FIELDS = ['input_assets','reference_assets','reference_images','reference_image_urls','reference_urls','product_image_urls','source_image_urls','product_images','source_images','source_image_url','source_image','first_frame_image','last_frame_image','start_image','end_image'];

    public static function inputs(array $roots): array
    {
        $items=[];
        foreach ($roots as $root) {
            $root=self::object($root);
            foreach (self::FIELDS as $field) if (isset($root[$field])) array_push($items,...self::items($root[$field]));
        }
        return $items;
    }

    /** Groups keep each original task's reference order; file lookup is batched across groups. */
    public static function resolveGroups(int $tenant, int $user, array $groups): array
    {
        if ($tenant<=0 || $user<=0) return array_map(static fn()=>[], $groups);
        $keys=[];
        foreach ($groups as $items) foreach ($items as $item) {
            $value=self::value($item);$uri=self::uri($value);
            if ($uri!=='') $keys[$uri]=$uri;
        }
        $files=[];
        if ($keys) {
            $rows=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>FileEnum::SOURCE_USER])
                ->whereIn('uri',array_values($keys))->where(function($q){$q->whereNull('delete_time')->whereOr('delete_time',0);})
                ->field('uri,storage_scope,storage_engine,storage_domain')->order('id','desc')->select()->toArray();
            foreach ($rows as $row) $files[$row['uri']] ??= $row;
        }
        $resolved=[];
        foreach ($groups as $key=>$items) {
            $refs=[];
            foreach ($items as $item) {
                $value=self::value($item);
                if ($value==='' || preg_match('/^(?:blob|data|javascript):/i',$value)) continue;
                $uri=self::uri($value);$file=$files[$uri] ?? null;
                $remote=(bool)preg_match('/^https?:\/\//i',$value);
                // A matching path on an unrelated external host is not the same file.
                $sameHost=!$remote || ($file && parse_url($value,PHP_URL_HOST)===parse_url($file['storage_domain'],PHP_URL_HOST));
                $url=$file && $sameHost ? FileService::getFileUrlByStorage($uri,$file['storage_scope'],$file['storage_engine'],$file['storage_domain'])
                    : ($remote ? $value : '');
                if ($url==='' || isset($refs[$url])) continue;
                $object=is_array($item)?$item:[];
                $type=(string)($object['type'] ?? $object['asset_type'] ?? '');
                $type=str_contains($type,'video') || preg_match('/\.(mp4|mov|webm)(?:[?#]|$)/i',$url) ? 'video'
                    : (str_contains($type,'audio') || preg_match('/\.(mp3|wav|m4a|aac)(?:[?#]|$)/i',$url) ? 'audio' : 'image');
                $refs[$url]=['url'=>$url,'type'=>$type,'title'=>self::text($object['title'] ?? $object['name'] ?? '')];
                foreach (['id','role'] as $field) if (isset($object[$field]) && is_scalar($object[$field])) $refs[$url][$field]=$object[$field];
            }
            $resolved[$key]=array_values($refs);
        }
        return $resolved;
    }

    public static function object($value): array
    {
        if (is_array($value)) return $value;
        $parsed=is_string($value)?json_decode($value,true):null;
        return is_array($parsed)?$parsed:[];
    }
    private static function items($value): array
    {
        if (is_string($value)) {
            $json=json_decode($value,true);
            if (is_array($json)) $value=$json;
        }
        if (is_array($value) && array_is_list($value)) return $value;
        return $value ? [$value] : [];
    }
    private static function value($item): string
    {
        if (is_string($item)) return trim($item);
        if (!is_array($item)) return '';
        foreach (['url','image_url','video_url','audio_url','file_url','uri'] as $field) {
            if (self::text($item[$field] ?? '')!=='') return self::text($item[$field]);
        }
        return '';
    }
    private static function uri(string $value): string
    {
        $path=parse_url($value,PHP_URL_PATH);
        return is_string($path) && preg_match('~(?:^|/)((?:uploads|storage)/[^?#]+)$~',$path,$match) && !str_contains($match[1],'..') ? $match[1] : '';
    }
    private static function text($value): string { return is_scalar($value)?trim((string)$value):''; }
}
