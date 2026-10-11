<?php
namespace app\common\service\open_platform;
use app\common\service\UploadService;
use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
final class OpenAssets
{
    public static function upload(int $tenantId,int $userId,string $type): array
    {
        $data=match($type){'image'=>UploadService::image(0,$userId,FileEnum::SOURCE_USER),'video'=>UploadService::video(0,$userId,FileEnum::SOURCE_USER),'file','audio'=>UploadService::file(0,$userId,FileEnum::SOURCE_USER),default=>throw new OpenException('invalid_asset_type','素材类型无效')};
        $native=TenantFile::where(['tenant_id'=>$tenantId,'source_id'=>$userId,'id'=>$data['id']])->findOrEmpty();if($native->isEmpty())throw new OpenException('asset_unavailable','上传归属信息无效',502);
        $file=request()->file('file');$id='of_'.bin2hex(random_bytes(16));$row=['tenant_id'=>$tenantId,'user_id'=>$userId,'file_id'=>$id,'uri'=>$native['uri'],'name'=>$native['name'],'mime'=>$file?$file->getMime():'','size'=>$file?$file->getSize():0,'storage_scope'=>$native['storage_scope']??'','storage_engine'=>$native['storage_engine']??'','storage_domain'=>$native['storage_domain']??'']+OpenStore::timestamp();OpenStore::table('file')->insert($row);return self::detail($tenantId,$userId,$id);
    }
    public static function detail(int $tenantId,int $userId,string $id): array
    {
        $row=OpenStore::owner('file',$tenantId,$userId)->where('file_id',$id)->find();if(!$row)throw new OpenException('not_found','素材不存在',404);return ['file_id'=>$row['file_id'],'name'=>$row['name'],'mime'=>$row['mime'],'size'=>$row['size'],'url'=>FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain'])];
    }
}
