<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\enum\FileEnum;
use app\common\service\FileService;
use app\common\service\storage\Driver;
use app\common\service\storage\StorageConfigService;
use app\common\service\open_platform\OpenSafeHttp;
use think\facade\Db;

final class BrandLogoService
{
    public static function persist(array $order,array $asset): array
    {
        $uri=(string)$asset['uri'];
        if (!preg_match('~^uploads/[a-zA-Z0-9/_\-.]+\.(png|jpe?g|webp)$~iD',$uri) || str_contains($uri,'..')) throw new BrandUserException('品牌Logo资料需要核对');
        $source=request()->source; $tenant=request()->tenantId;
        try {
            request()->source=\app\common\enum\AdminTerminalEnum::TENANT; request()->tenantId=(int)$order['tenant_id'];
            if (($asset['storage_engine']??'local')==='local') {
                $root=realpath(public_path().'uploads'); $path=realpath(public_path().$uri);
                if (!$root || !$path || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || filesize($path)>5242880) throw new BrandUserException('源Logo不存在或超出大小限制，请平台核对');
                $bytes=file_get_contents($path);
            } else {
                $url=FileService::getFileUrlByStorage($uri,$asset['storage_scope']??'platform',$asset['storage_engine']??'', $asset['storage_domain']??'');
                $bytes=OpenSafeHttp::get($url,5242880,10)['body'];
            }
        } finally { request()->source=$source; request()->tenantId=$tenant; }
        $size=@getimagesizefromstring($bytes?:'');
        $ext=match($size['mime']??'') { 'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp',default=>throw new BrandUserException('品牌Logo内容不是有效图片') };
        $name=hash('sha256',$bytes).'.'.$ext;
        $dir='uploads/oem_brands/'.$order['order_sn'];
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',(string)$order['order_sn'])) throw new \RuntimeException('Invalid OEM artifact order identifier');
        $config=StorageConfigService::getEffectiveConfig(0);
        $tmp=tempnam(sys_get_temp_dir(),'oem_logo_');
        if ($tmp===false) throw new \RuntimeException('Unable to stage OEM logo');
        $file=$tmp.'.'.$ext; rename($tmp,$file); file_put_contents($file,$bytes);
        try {
            $driver=new Driver($config); $driver->setUploadFileByReal($file,$name);
            if (!$driver->upload($dir)) throw new \RuntimeException('OEM logo storage failed: '.$driver->getError());
        } finally { @unlink($file); }
        return ['uri'=>$dir.'/'.$name,'storage_scope'=>'platform','storage_engine'=>$config['default']??'local','storage_domain'=>StorageConfigService::getStorageDomain($config),'sha256'=>hash('sha256',$bytes)];
    }

    public static function display(int $tenantId,string $uri): string
    {
        $value=Db::name(BrandCatalogService::tenantTable('tenant_config',$tenantId))->where(['tenant_id'=>$tenantId,'type'=>'oem_brand','name'=>'logo_meta'])->value('value');
        $meta=json_decode((string)$value,true)?:[];
        if (($meta['uri']??'')!==$uri) return $uri;
        return FileService::getFileUrlByStorage($uri,$meta['storage_scope']??'platform',$meta['storage_engine']??'local',$meta['storage_domain']??'');
    }
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
