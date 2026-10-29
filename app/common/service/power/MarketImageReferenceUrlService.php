<?php

namespace app\common\service\power;

use app\common\service\FileService;
use think\facade\Db;

/** Resolve stored canvas image references to URLs accepted by market providers. */
final class MarketImageReferenceUrlService
{
    /** Keep typed uploads and legacy URL aliases on the same durable source. */
    public static function normalizeRequest(array $request,int $tenantId): array
    {
        $cache=[];$resolve=static function(string $source)use(&$cache,$tenantId):string{
            return $cache[$source]??=self::resolve([$source],$tenantId)[0]??'';
        };
        $references=[];
        foreach((array)($request['reference_images']??[])as$reference){
            $source=trim((string)$reference);if($source!=='')$references[]=$resolve($source);
        }
        // Admission counts input slots before legacy codecs apply their own URL deduplication.
        $request['reference_images']=$references;
        if(!is_array($request['reference_assets']??null))return $request;
        $images=[];
        foreach($request['reference_assets']as$i=>$asset){
            if(!is_array($asset)||($asset['type']??'image')!=='image')continue;
            $uri=trim((string)($asset['uri']??''));$url=trim((string)($asset['url']??''));
            $source=$uri!==''&&!preg_match('~^blob:~i',$uri)?$uri:$url;
            if(!empty($asset['uploading'])||$source==='')throw new \InvalidArgumentException('参考图片尚未上传完成，请重新上传');
            $resolved=$resolve($source);
            $request['reference_assets'][$i]=array_replace($asset,['url'=>$resolved,'uri'=>$resolved]);
            $images[]=$resolved;
        }
        if($request['reference_images']===[]&&$images!==[])$request['reference_images']=$images;
        return $request;
    }

    /** @return array<int, string> */
    public static function resolve(array $references, int $tenantId): array
    {
        $urls = [];
        foreach ($references as $reference) {
            $url = trim((string)$reference);
            if ($url === '') {
                continue;
            }
            if(preg_match('~^[a-z][a-z0-9+.-]*:~i',$url)&&!preg_match('~^(?:https?://|data:image/)~i',$url))throw new \InvalidArgumentException('参考图片需要上传后的文件地址，请重新上传');
            if (preg_match('/^(https?:\/\/|data:image\/)/i', $url) !== 1) {
                $uri = ltrim($url, '/');
                $file = $tenantId > 0
                    ? Db::name('tenant_file')->where(['tenant_id' => $tenantId, 'uri' => $uri])->order('id', 'desc')->find()
                    : null;
                $url = !empty($file)
                    ? FileService::getFileUrlByStorage($uri, (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''))
                    : FileService::getFileUrl($uri);
            }
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
