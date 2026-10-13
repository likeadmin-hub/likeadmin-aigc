<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\enum\FileEnum;
use app\common\service\FileService;
use think\facade\Db;

final class BrandLogoService
{
    public static function snapshot(int $tenantId,int $userId,string $uri,int $id): array
    {
        $query=Db::name(BrandCatalogService::tenantTable('tenant_file',$tenantId))
            ->where(['tenant_id'=>$tenantId,'source'=>FileEnum::SOURCE_USER,'source_id'=>$userId,'type'=>FileEnum::IMAGE_TYPE])->whereNull('delete_time');
        if ($id>0) $query->where('id',$id); else $query->where('uri',$uri);
        $file=$query->find();
        if (!$file || ($uri!=='' && !hash_equals((string)$file['uri'],$uri))) throw new BrandUserException('品牌Logo不存在或不属于您的账号，请重新上传');
        if (!preg_match('~^uploads/[a-zA-Z0-9/_\-.]+\.(png|jpe?g|webp)$~iD',(string)$file['uri']) || str_contains($file['uri'],'..')) throw new BrandUserException('请上传PNG、JPEG或WebP品牌Logo');
        $asset=array_intersect_key($file,array_flip(['id','uri','storage_scope','storage_engine','storage_domain']));
        return ['logo'=>(string)$file['uri'],'logo_id'=>(int)$file['id'],
            'logo_url'=>FileService::getFileUrlByStorage($file['uri'],$file['storage_scope']??'platform',$file['storage_engine']??'local',$file['storage_domain']??''),
            'logo_asset'=>$asset];
    }
}
