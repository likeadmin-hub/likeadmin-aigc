<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_motion_sync;
use app\common\service\app\aigc_micro_video\MicroVideoMedia;
use app\common\service\ai\StudioMediaService;
use app\common\service\storage\ManagedStorageReader;
use InvalidArgumentException;

final class MotionSyncMedia
{
    /** The tool accepts a conservative common subset. Version-specific requirements are checked separately. */
    public static function validate(int $tenant,int $user,array $body): array
    {
        if($body['image']){
            $image=MicroVideoMedia::validateImages($tenant,$user,[$body['image']],1)[0];
            $approved=\app\common\service\app\StudioBuiltinCatalog::asset($image['uri'],$image['storage_engine'],$image['storage_domain']);
            $key='motion_image_facts:'.hash('sha256',json_encode([$tenant,$user,$image]));$facts=null;try{$facts=\think\facade\Cache::get($key);}catch(\Throwable){}
            if($approved&&($approved['type']??'')==='image'&&isset($approved['mime_type'],$approved['file_size'],$approved['width'],$approved['height']))$facts=array_intersect_key($approved,array_flip(['mime_type','file_size','width','height']));
            if(!is_array($facts)){$facts=StudioMediaService::facts($tenant,$image,10);try{\think\facade\Cache::set($key,$facts,120);}catch(\Throwable){}}
            $body['image']=array_replace($image,$facts);
        }
        if($body['video']){
            // Public DTOs deliberately omit file IDs. Recover the exact owned receipt first.
            $input=(string)($body['video']['uri']??$body['video']['url']??'');
            $file=\think\facade\Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source'=>1,'source_id'=>$user,'type'=>20,'uri'=>$input])->whereRaw('(delete_time IS NULL OR delete_time=0)')->find();
            $approved=$file?\app\common\service\app\StudioBuiltinCatalog::asset((string)$file['uri'],(string)$file['storage_engine'],(string)$file['storage_domain']):null;
            if($approved&&($approved['type']??'')==='video'&&isset($approved['mime_type'],$approved['file_size'],$approved['width'],$approved['height'],$approved['duration']))
                $body['video']=array_replace($approved,array_intersect_key($file,array_flip(['storage_scope','storage_engine','storage_domain'])),['file_id'=>(int)$file['id'],'tenant_id'=>$tenant,'user_id'=>$user,'url'=>$approved['storage_domain'].'/'.$approved['uri']]);
            else $body['video']=MicroVideoMedia::validateVideo($tenant,$user,$input,30,
                static fn(array $file,array $storage): string=>ManagedStorageReader::read(array_replace($file,$storage),$tenant,100*1024*1024,60));
        }
        self::assertFacts($body);
        return $body;
    }
    public static function assertFacts(array $body): void
    {
        $image=(array)($body['image']??[]);$video=(array)($body['video']??[]);
        if($image&&(!in_array($image['mime_type']??'',['image/jpeg','image/png'],true)||(int)($image['file_size']??0)>10*1024*1024))throw new InvalidArgumentException('本工具接受不超过 10MB 的 JPG、PNG 角色图片');
        if($video&&(!in_array($video['mime_type']??'',['video/mp4','video/quicktime'],true)||(int)($video['file_size']??0)>100*1024*1024))throw new InvalidArgumentException('本工具接受不超过 100MB 的 MP4、MOV 动作视频');
        // These dimensional requirements are explicitly published for 2.6. Do not infer 3.0's declaration from them.
        if($image&&($body['model_key']??'')==='kling-2.6-motion-control'){
            $w=(int)($image['width']??0);$h=(int)($image['height']??0);
            if(min($w,$h)<=300||$h<=0||$w/$h<0.4||$w/$h>2.5)throw new InvalidArgumentException('Kling 2.6 角色图片的宽高须大于 300px，宽高比须在 1:2.5～2.5:1');
        }
    }
}
