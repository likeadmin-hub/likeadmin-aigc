<?php
namespace app\common\service\app\aigc_music;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\service\storage\Driver;
use app\common\service\storage\StorageConfigService;
use app\common\service\open_platform\{OpenSafeHttp,OpenException};

/** Store real exported bytes, using the same tenant storage service as user uploads. */
final class AigcMusicExportStorageService
{
    public static function validate(string $type,string $bytes): string
    {
        $valid=MusicExportValidator::valid($type,$bytes);
        if(!$valid)throw new OpenException('result_storage_failed','上游导出结果不是所需格式，等待恢复',502);
        return match($type){'wav'=>'audio/wav','midi'=>'audio/midi','mp4'=>'video/mp4'};
    }
    public static function persist(int $tenant,int $user,string $type,string $url): array
    {
        if($tenant<1||$user<1)throw new OpenException('task_context_invalid','导出结果归属无效',409);
        $wire=OpenSafeHttp::get($url,104857600,45);$mime=self::validate($type,$wire['body']);
        $temp=tempnam(sys_get_temp_dir(),'music_export_');if($temp===false)throw new OpenException('result_storage_failed','无法创建结果临时文件',503);
        $file=$temp.'.'.$type;
        try{
            if(!rename($temp,$file)||file_put_contents($file,$wire['body'])!==strlen($wire['body']))throw new OpenException('result_storage_failed','结果临时文件写入失败',503);
            $config=StorageConfigService::getEffectiveConfig($tenant);$storage=new Driver($config);$storage->setUploadFileByReal($file);$dir='uploads/aigc_music/export/'.date('Ymd');
            if(!$storage->upload($dir))throw new OpenException('result_storage_failed','导出结果保存失败，等待恢复',503);
            $uri=$dir.'/'.str_replace('\\','/',$storage->getFileName());$scope=$config['scope']??'tenant';$engine=$config['default']??'local';$domain=StorageConfigService::getStorageDomain($config);
            TenantFile::create(['tenant_id'=>$tenant,'cid'=>0,'type'=>FileEnum::FILE_TYPE,'name'=>basename($uri),'uri'=>$uri,'storage_scope'=>$scope,'storage_engine'=>$engine,'storage_domain'=>$domain,'source'=>FileEnum::SOURCE_USER,'source_id'=>$user,'create_time'=>time()]);
            return ['file_uri'=>$uri,'file_url'=>FileService::getFileUrlByStorage($uri,$scope,$engine,$domain),'storage_scope'=>$scope,'storage_engine'=>$engine,'storage_domain'=>$domain,'mime_type'=>$mime,'file_size'=>strlen($wire['body']),'sha256'=>hash('sha256',$wire['body']),'stored'=>true];
        }finally{@unlink($temp);@unlink($file);}
    }
}
