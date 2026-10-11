<?php
namespace app\common\service\app\aigc_music;

use app\common\model\app\aigc_music\AigcMusicExport;
use app\common\model\file\TenantFile;
use app\common\enum\FileEnum;
use app\common\service\FileService;
use app\common\service\open_platform\{OpenException,OpenStore,OpenSafeHttp};

/** Download an owned, already verified export; no caller supplied URL or storage scope. */
final class AigcMusicExportDownload
{
    public static function source(int $tenant,int $user,int $id): array
    {
        if($tenant<1||$user<1||$id<1)throw new OpenException('not_found','导出文件不存在',404);
        $export=AigcMusicExport::where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success'])->findOrEmpty();
        if($export->isEmpty())throw new OpenException('not_found','导出文件不存在',404);
        $meta=OpenStore::json($export['result_json']);$type=(string)$export['export_type'];
        $mime=['wav'=>'audio/wav','midi'=>'audio/midi','mp4'=>'video/mp4'][$type]??'';
        if($mime===''||!preg_match('/^[a-f0-9]{64}$/D',(string)($meta['sha256']??''))||($meta['mime_type']??'')!==$mime||(int)($meta['file_size']??0)<1||(int)$meta['file_size']>104857600)throw new OpenException('result_unverified','导出文件尚未完成校验',409);
        $file=TenantFile::where(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>FileEnum::SOURCE_USER,'uri'=>(string)$export['file_uri']])->findOrEmpty();
        if($file->isEmpty())throw new OpenException('not_found','导出文件不存在',404);
        foreach(['storage_scope','storage_engine','storage_domain'] as $field)if((string)$file[$field]!==(string)$export[$field])throw new OpenException('result_unverified','导出文件存储记录不一致',409);
        return ['uri'=>(string)$file['uri'],'url'=>FileService::getFileUrlByStorage((string)$file['uri'],(string)$file['storage_scope'],(string)$file['storage_engine'],(string)$file['storage_domain']),'local'=>$file['storage_engine']==='local','mime'=>$mime,'name'=>'aigc-music-export-'.$id.'.'.$type,'size'=>(int)$meta['file_size'],'sha256'=>$meta['sha256']];
    }
    public static function read(int $tenant,int $user,int $id): array
    {
        $source=self::source($tenant,$user,$id);
        if($source['local']){
            $root=realpath(public_path().'uploads/aigc_music/export');$file=realpath(public_path().ltrim($source['uri'],'/'));
            if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)||filesize($file)!==$source['size'])throw new OpenException('result_storage_failed','导出文件暂不可读取',503);
            $bytes=file_get_contents($file);
        }else $bytes=OpenSafeHttp::get($source['url'],104857600,45)['body'];
        if(!is_string($bytes)||strlen($bytes)!==$source['size']||!hash_equals($source['sha256'],hash('sha256',$bytes)))throw new OpenException('result_storage_failed','导出文件校验失败，请稍后重试',503);
        return ['body'=>$bytes,'mime'=>$source['mime'],'name'=>$source['name']];
    }
}
