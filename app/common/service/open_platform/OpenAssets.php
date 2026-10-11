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
    /** Materialize owned, already stored native outputs for subsequent tool API calls. */
    public static function registerResult(int $tenantId,int $userId,string $app,array $result): ?string
    {
        OpenToolRegistry::get($app);if($tenantId<1||$userId<1||($result['stored']??true)===false)return null;
        foreach(['tenant_id'=>$tenantId,'user_id'=>$userId] as $field=>$owner)if(isset($result[$field])&&(int)$result[$field]!==$owner)throw new OpenException('not_found','结果不属于当前用户',404);
        $uri='';foreach(['image_uri','video_uri','audio_uri','file_uri'] as $field)if(!empty($result[$field])){$uri=(string)$result[$field];break;}
        if($uri===''||empty($result['storage_scope'])||empty($result['storage_engine']))return null;
        $storage=['storage_scope'=>$result['storage_scope'],'storage_engine'=>$result['storage_engine'],'storage_domain'=>(string)($result['storage_domain']??'')];
        $id='of_'.substr(hash('sha256',OpenStore::encode([$tenantId,$userId,$app,$uri,$storage])),0,32);
        $row=['tenant_id'=>$tenantId,'user_id'=>$userId,'file_id'=>$id,'uri'=>$uri,'name'=>basename((string)(parse_url($uri,PHP_URL_PATH)?:$uri)),'mime'=>(string)($result['mime']??$result['mime_type']??''),'size'=>max(0,(int)($result['size']??$result['file_size']??0))]+$storage+OpenStore::timestamp();
        // The deterministic ID and unique file_id index make polling/recovery idempotent.
        $existing=OpenStore::table('file')->where('file_id',$id)->find();
        if(!$existing){
            try{OpenStore::table('file')->insert($row);}
            catch(\Throwable $error){
                $code=0;if($error instanceof \PDOException)$code=(int)($error->errorInfo[1]??0);
                elseif($error instanceof \think\db\exception\DbException)$code=(int)($error->getData()['PDO Error Info']['Driver Error Code']??0);
                if($code!==1062)throw $error;
            }
            $existing=OpenStore::table('file')->where('file_id',$id)->find();
        }
        foreach(['tenant_id','user_id','uri','storage_scope','storage_engine','storage_domain'] as $field)
            if(!$existing||(string)$existing[$field]!==(string)$row[$field])throw new OpenException('asset_identity_conflict','结果素材归属或存储信息冲突',500);
        return $id;
    }
    public static function detail(int $tenantId,int $userId,string $id): array
    {
        $row=OpenStore::owner('file',$tenantId,$userId)->where('file_id',$id)->find();if(!$row)throw new OpenException('not_found','素材不存在',404);return ['file_id'=>$row['file_id'],'name'=>$row['name'],'mime'=>$row['mime'],'size'=>$row['size'],'url'=>FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain'])];
    }
}
