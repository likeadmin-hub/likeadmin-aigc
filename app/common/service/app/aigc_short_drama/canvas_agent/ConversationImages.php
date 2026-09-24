<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;
use app\common\service\FileService;

final class ConversationImages
{
    private const IMAGE_TYPES=['reference_image','canvas_image','shot_image','character_image','scene_image','subject_image','three_view'];

    public static function freezeAsset(int $tenant,int $user,int $canvas,int $assetId): array
    {
        $row=Db::name('aigc_short_drama_asset')->where(['id'=>$assetId,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->find();
        if (!$row || !in_array((string)$row['asset_type'],self::IMAGE_TYPES,true) || !in_array((int)$row['canvas_id'],[0,$canvas],true) || (string)$row['uri']==='') throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
        return ['id'=>(int)$row['id'],'uri'=>(string)$row['uri'],'storage_scope'=>(string)$row['storage_scope'],'storage_engine'=>(string)$row['storage_engine'],'storage_domain'=>(string)$row['storage_domain']];
    }

    public static function freeze(int $tenant,int $user,int $canvas,string $url): array
    {
        // Resolve only this app's owned, ready assets. Never fetch a browser URL.
        $rows=Db::name('aigc_short_drama_asset')->where(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0,'status'=>'ready'])->whereIn('asset_type',['reference_image','canvas_image','shot_image','character_image','scene_image'])->select()->toArray();
        foreach ($rows as $row) {
            $resolved=FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
            if ($url!==$row['uri'] && $url!==$resolved) continue;
            return ['id'=>(int)$row['id'],'uri'=>$row['uri'],'storage_scope'=>$row['storage_scope'],'storage_engine'=>$row['storage_engine'],'storage_domain'=>$row['storage_domain']];
        }
        throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
    }

    public static function urls(int $tenant,int $user,array $context): array
    {
        $assets=[];
        if (count(array_filter($context['selected_nodes']??[],static fn($node)=>($node['type']??'')==='image'))>4) throw new RuntimeException('TOO_MANY_IMAGE_REFERENCES');
        foreach ($context['selected_nodes']??[] as $node) {
            if (($node['type']??'')!=='image') continue;
            $assets[]=$node['image_asset']??[];
        }
        foreach ($context['attachment_images']??[] as $image) $assets[]=$image;
        if (count($assets)>4) throw new RuntimeException('TOO_MANY_IMAGE_REFERENCES');
        $urls=[];
        foreach ($assets as $image) {
            $row=Db::name('aigc_short_drama_asset')->where(['id'=>(int)($image['id']??0),'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->find();
            if (!$row || $row['uri']!==($image['uri']??null) || $row['storage_scope']!==($image['storage_scope']??null) || $row['storage_engine']!==($image['storage_engine']??null) || $row['storage_domain']!==($image['storage_domain']??null)) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
            $storage=self::effectiveStorage($row,$tenant,$user);
            if ($storage['storage_engine']==='local') {
                $uri=$row['uri'];
                if (preg_match('#^https?://#i',$uri)) $uri=(string)parse_url($uri,PHP_URL_PATH);
                $uri=ltrim($uri,'/');
                $ownedUpload=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>10,'uri'=>$uri,'storage_engine'=>'local'])->whereRaw('(delete_time IS NULL OR delete_time = 0)')->find();
                $ownedGenerated=false;
                if (!$ownedUpload && $row['task_id']!=='') {
                    $task=Db::name('aigc_short_drama_generation_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$row['canvas_id'],'task_id'=>$row['task_id'],'delete_time'=>0])->find();
                    $ownedGenerated=$task && in_array((int)$row['id'],array_map('intval',json_decode($task['output_asset_ids']?:'[]',true)),true);
                }
                // Asset registration alone is not proof of file ownership:
                // a browser can submit an arbitrary URI to the asset library.
                if (!$ownedUpload && !$ownedGenerated) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
                $root=realpath(app()->getRootPath().'public/uploads');
                $path=realpath(app()->getRootPath().'public/'.ltrim($uri,'/'));
                if (!$root || !$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path)>8*1024*1024) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
                $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (!in_array($mime,['image/png','image/jpeg','image/webp'],true)) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
                $bytes=file_get_contents($path);
                if ($bytes===false) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
                $urls[]='data:'.$mime.';base64,'.base64_encode($bytes);
                continue;
            }
            $url=FileService::getFileUrlByStorage($row['uri'],$storage['storage_scope'],$storage['storage_engine'],$storage['storage_domain']);
            if (!preg_match('#^https?://#i',$url)) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
            $urls[]=$url;
        }
        if (count($urls)>4) throw new RuntimeException('TOO_MANY_IMAGE_REFERENCES');
        return $urls;
    }

    /**
     * Earlier PC uploads could persist empty storage columns on the app asset
     * while the tenant-owned upload record had the real OSS metadata.  Keep
     * the frozen asset comparison strict, then recover only blank values from
     * that same user's verified tenant_file row before a Provider request.
     */
    private static function effectiveStorage(array $row,int $tenant,int $user): array
    {
        $storage=['storage_scope'=>(string)$row['storage_scope'],'storage_engine'=>(string)$row['storage_engine'],'storage_domain'=>(string)$row['storage_domain']];
        if ($storage['storage_scope']!=='' && $storage['storage_engine']!=='' && $storage['storage_domain']!=='') return $storage;
        $uri=(string)$row['uri'];
        if (preg_match('#^https?://#i',$uri)) $uri=(string)parse_url($uri,PHP_URL_PATH);
        $uri=ltrim($uri,'/');
        if ($uri==='') return $storage;
        $upload=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>10,'uri'=>$uri])->whereRaw('(delete_time IS NULL OR delete_time = 0)')->order('id','desc')->find();
        if (!$upload) return $storage;
        foreach (array_keys($storage) as $key) if ($storage[$key]==='' && (string)($upload[$key]??'')!=='') $storage[$key]=(string)$upload[$key];
        return $storage;
    }
}
