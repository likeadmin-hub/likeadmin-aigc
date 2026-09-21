<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;
use app\common\service\FileService;

final class ConversationImages
{
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
        $urls=[];
        foreach ($context['selected_nodes']??[] as $node) {
            if (($node['type']??'')!=='image') continue;
            $image=$node['image_asset']??[];
            $row=Db::name('aigc_short_drama_asset')->where(['id'=>(int)($image['id']??0),'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->find();
            if (!$row || $row['uri']!==($image['uri']??null) || $row['storage_scope']!==($image['storage_scope']??null) || $row['storage_engine']!==($image['storage_engine']??null) || $row['storage_domain']!==($image['storage_domain']??null)) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
            $url=FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
            if (!preg_match('#^https?://#i',$url)) throw new RuntimeException('IMAGE_REFERENCE_UNAVAILABLE');
            $urls[]=$url;
        }
        if (count($urls)>4) throw new RuntimeException('TOO_MANY_IMAGE_REFERENCES');
        return $urls;
    }
}
