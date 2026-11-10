<?php
declare(strict_types=1);
namespace app\common\service\ai;
use app\common\service\FileService;
use app\common\service\power\MarketGenerationMedia;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\StorageSignedUrlService;
use InvalidArgumentException;
use think\facade\Db;

/** Authoritative admin receipts and metadata-only grants for published studio templates. */
final class StudioMediaService
{
    public static function adminAsset(int $scope,array $input,int $type): array
    {
        $q=Db::name($scope>0?'tenant_file':'file')->where(['id'=>(int)($input['file_id']??0),'type'=>$type,'source'=>0])->whereRaw('(delete_time IS NULL OR delete_time=0)');
        if($scope>0)$q->where('tenant_id',$scope);$file=$q->find();
        if(!$file)throw new InvalidArgumentException('请选择当前后台素材库中的有效素材');
        return array_intersect_key($file,array_flip(['uri','name','storage_scope','storage_engine','storage_domain']))+['file_id'=>(int)$file['id'],'tenant_id'=>$scope,'user_id'=>0];
    }
    public static function verify(int $scope,array $asset,int $type): array
    {
        $q=Db::name($scope>0?'tenant_file':'file')->where(['id'=>(int)($asset['file_id']??0),'type'=>$type])->whereRaw('(delete_time IS NULL OR delete_time=0)');
        if($scope>0)$q->where('tenant_id',$scope);$file=$q->find();
        if(!$file)throw new InvalidArgumentException('模板素材已不可用，请重新上传');
        foreach(['uri','storage_scope','storage_engine','storage_domain'] as $key)if((string)($asset[$key]??'')!==(string)($file[$key]??''))throw new InvalidArgumentException('模板素材归属已变化，请更新模板');
        if((int)($asset['user_id']??0)>0&&((int)$file['source']!==1||(int)$file['source_id']!==(int)$asset['user_id']))throw new InvalidArgumentException('模板素材归属无效');
        if($scope===0&&($file['storage_scope']??'')!=='platform')throw new InvalidArgumentException('共享模板须使用平台存储素材');
        return $asset;
    }
    public static function facts(int $scope,array $asset,int $type): array
    {
        self::verify($scope,$asset,$type);$uri=(string)$asset['uri'];$temporary=null;
        try{
            if($asset['storage_engine']==='local'){
                if(str_contains($uri,'..')||preg_match('~^[a-z]+://~i',$uri)||str_contains($uri,"\0"))throw new InvalidArgumentException('素材路径无效');
                $path=realpath(public_path().ltrim($uri,'/'));$root=realpath(public_path());
                if(!$path||!$root||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path))throw new InvalidArgumentException('素材文件不存在');
            }else{
                $bytes=\app\common\service\storage\ManagedStorageReader::read($asset,$scope,($type===10?10:100)*1024*1024,30);$temporary=tempnam(sys_get_temp_dir(),'studio-media-');
                if(!$temporary||file_put_contents($temporary,$bytes)!==strlen($bytes))throw new InvalidArgumentException('无法读取素材');$path=$temporary;unset($bytes);
            }
            if(filesize($path)>($type===10?10:200)*1024*1024)throw new InvalidArgumentException('素材大小超出限制');
            if($type===20)return MarketGenerationMedia::localFacts($path,'video');
            $size=@getimagesize($path);if(!$size||!in_array($size['mime']??'',['image/jpeg','image/png','image/webp'],true))throw new InvalidArgumentException('请上传有效 JPG、PNG 或 WebP 图片');
            return ['width'=>$size[0],'height'=>$size[1],'file_size'=>(int)filesize($path),'mime_type'=>$size['mime']];
        }finally{if($temporary)@unlink($temporary);}
    }
    public static function grant(int $tenant,int $user,int $sourceScope,array $asset,int $type): array
    {
        if($sourceScope!==0&&$sourceScope!==$tenant)throw new InvalidArgumentException('不能引用其他租户素材');self::verify($sourceScope,$asset,$type);
        $key=['tenant_id'=>$tenant,'user_id'=>$user];
        $where=['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>$type]+array_intersect_key($asset,array_flip(['uri','storage_scope','storage_engine','storage_domain']));
        $old=Db::name('tenant_file')->where($where)->whereRaw('(delete_time IS NULL OR delete_time=0)')->find();
        $id=$old?(int)$old['id']:Db::name('tenant_file')->insertGetId($where+['name'=>$asset['name']??basename($asset['uri']),'upload_origin'=>'reference','create_time'=>time(),'update_time'=>time(),'delete_time'=>0]);
        return array_replace($asset,$key,['file_id'=>$id]);
    }
}
