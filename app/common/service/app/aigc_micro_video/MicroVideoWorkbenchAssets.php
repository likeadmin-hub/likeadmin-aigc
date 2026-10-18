<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\enum\FileEnum;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\FileService;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\StorageSignedUrlService;
use think\facade\Db;
use InvalidArgumentException;

/** Explicit authorized copies bridge shared business assets to unchanged private execution ownership. */
final class MicroVideoWorkbenchAssets
{
    public static function catalogImages(int $tenant,array $images): array
    {
        if(count($images)>15)throw new InvalidArgumentException('最多15张资料图片');$out=[];
        foreach($images as $image){$uri=self::uri((string)($image['uri']??$image['url']??''));$row=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source'=>FileEnum::SOURCE_ADMIN,'type'=>FileEnum::IMAGE_TYPE,'uri'=>$uri])->whereRaw('(delete_time IS NULL OR delete_time=0)')->order('id','desc')->find();if(!$row)throw new InvalidArgumentException('共享图片须来自本租户后台素材库');$out[]=self::stored($tenant,$row)+['file_id'=>(int)$row['id'],'name'=>(string)($row['name']??basename($uri))];}
        return $out;
    }
    private static function uri(string $input): string
    {$path=ltrim((string)parse_url($input,PHP_URL_PATH),'/');if(!str_starts_with($path,'uploads/')||preg_match('#(^|/)\.{1,2}(/|$)|[\\\\%\x00-\x20]#',$path))throw new InvalidArgumentException('素材路径无效');return $path;}
    private static function stored(int $tenant,array $row): array
    {
        $storage=array_intersect_key($row,array_flip(['storage_scope','storage_engine','storage_domain']));$config=StorageConfigService::getStoredFileConfig($tenant,(string)($storage['storage_scope']??''),(string)($storage['storage_engine']??''));$storage['storage_scope']=($storage['storage_scope']??'')?:($config['scope']??'tenant');$storage['storage_engine']=($storage['storage_engine']??'')?:($config['default']??'local');$storage['storage_domain']=$storage['storage_domain']??'';
        $uri=(string)$row['uri'];$url=$storage['storage_engine']==='local'?'/'.$uri:FileService::getFileUrlByStorage($uri,$storage['storage_scope'],$storage['storage_engine'],$storage['storage_domain']);return ['uri'=>$uri,'url'=>$url]+$storage;
    }
    public static function decorative(int $tenant,int $user,string $input,bool $video=false): string
    {
        if($input==='')return '';if(str_starts_with(ltrim($input,'/'),'resource/')){$uri=ltrim($input,'/');if(str_contains($uri,'..')||str_contains($uri,'\\')||str_contains($uri,'%')||!preg_match('#^resource/[a-zA-Z0-9_./-]+$#D',$uri))throw new InvalidArgumentException('示例素材路径无效');return '/'.$uri;}
        $uri=self::uri($input);if(!$video){if($user>0)return MicroVideoMedia::validateImages($tenant,$user,[['uri'=>$uri]],1)[0]['url'];return self::catalogImages($tenant,[['uri'=>$uri]])[0]['url'];}
        return self::sourceAsset($tenant,$user,$uri,FileEnum::VIDEO_TYPE)['url'];
    }
    /** Resolve a managed upload receipt at its actual original ownership; client URLs grant nothing. */
    private static function sourceAsset(int $tenant,int $owner,string $input,int $type): array
    {
        $uri=self::uri($input);
        if($type===FileEnum::IMAGE_TYPE)return $owner>0?MicroVideoMedia::validateImages($tenant,$owner,[['uri'=>$uri]],1)[0]:self::catalogImages($tenant,[['uri'=>$uri]])[0];
        $query=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'type'=>FileEnum::VIDEO_TYPE,'uri'=>$uri,'source'=>$owner>0?FileEnum::SOURCE_USER:FileEnum::SOURCE_ADMIN])->whereRaw('(delete_time IS NULL OR delete_time=0)');
        if($owner>0)$query->where('source_id',$owner);$row=$query->find();if(!$row)throw new InvalidArgumentException('示例视频不属于当前素材范围');return self::stored($tenant,$row)+['file_id'=>(int)$row['id']];
    }
    public static function importAsset(int $tenant,int $user,array $p): array
    {
        $kind=MicroVideoWorkbenchPolicy::kind((string)($p['kind']??''));if($kind==='project')throw new InvalidArgumentException('请选择模板、角色或商品素材');$r=MicroVideoWorkbenchRecords::owned($tenant,$user,$kind,(int)($p['id']??0));$body=MicroVideoStore::json($r['body_json']);$index=(int)($p['image_index']??0);$images=(array)($body['images']??[]);if(!isset($images[$index]))throw new InvalidArgumentException('所选资料尚未添加图片');
        if((int)$r['user_id']===$user)return MicroVideoWorkbenchRecords::publicAsset(MicroVideoMedia::validateImages($tenant,$user,[$images[$index]],1)[0]);$images=(int)$r['user_id']>0?MicroVideoMedia::validateImages($tenant,(int)$r['user_id'],[$images[$index]],1):self::catalogImages($tenant,[$images[$index]]);return MicroVideoWorkbenchRecords::publicAsset(self::copy($tenant,$user,'catalog',(int)$r['id'],$images[0]));
    }
    public static function cloneBody(int $tenant,int $user,int $recordId,array $body,int $sourceOwner=0): array
    {
        foreach($body as $key=>$value){
            if($key==='images'){$images=[];foreach($value?($sourceOwner>0?MicroVideoMedia::validateImages($tenant,$sourceOwner,(array)$value,15):self::catalogImages($tenant,(array)$value)):[] as $image)$images[]=self::copy($tenant,$user,'catalog',$recordId,$image);$body[$key]=$images;}
            elseif(in_array($key,['cover_image','example_video'],true)&&is_string($value)&&$value!==''&&!str_starts_with(ltrim($value,'/'),'resource/')){
                $type=$key==='example_video'?FileEnum::VIDEO_TYPE:FileEnum::IMAGE_TYPE;$source=self::sourceAsset($tenant,$sourceOwner,$value,$type);
                $owned=self::copy($tenant,$user,$type===FileEnum::VIDEO_TYPE?'catalog_video':'catalog',$recordId,$source,$type);$body[$key]=$owned['url'];
            }elseif(is_array($value))$body[$key]=self::cloneBody($tenant,$user,$recordId,$value,$sourceOwner);
        }
        return $body;
    }
    private static function sourceAvailable(int $tenant,int $user): void
    {if(AppAccessService::assertTenantCanUse($tenant,'aigc_short_drama',$user,true,false)!==null)throw new InvalidArgumentException('请先开通短剧创作，再使用主体库；也可直接上传角色图');}
    public static function subjects(int $tenant,int $user,array $p): array
    {self::sourceAvailable($tenant,$user);return AigcShortDramaService::subjectLibraryLists($tenant,$user,$p,false);}
    public static function importSubject(int $tenant,int $user,int $id): array
    {self::sourceAvailable($tenant,$user);$source=AigcShortDramaService::agentSubjectReference($tenant,$user,$id);$source['uri']=$source['image_uri'];return MicroVideoWorkbenchRecords::publicAsset(self::copy($tenant,$user,'subject',$id,$source));}
    private static function copy(int $tenant,int $user,string $kind,int $id,array $source,int $type=FileEnum::IMAGE_TYPE): array
    {
        if($user<=0)throw new InvalidArgumentException('请使用用户创作入口引用共享素材');$identity=MicroVideoWorkbenchPolicy::hash(array_intersect_key($source,array_flip(['uri','storage_scope','storage_engine','storage_domain'])));$where=['tenant_id'=>$tenant,'user_id'=>$user,'source_kind'=>$kind,'source_id'=>$id,'source_hash'=>$identity];$prior=MicroVideoWorkbenchRecords::table('asset')->where($where)->find();
        if($prior){$img=MicroVideoStore::json($prior['image_json']);try{return self::sourceAsset($tenant,$user,(string)$img['uri'],$type);}catch(\Throwable){/* An intentionally deleted private copy can be restored from the still authorized source. */}}
        $lock=fopen(runtime_path().'micro_workbench_asset_'.hash('sha256',MicroVideoStore::encode($where)).'.lock','c');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);throw new InvalidArgumentException('素材复制繁忙，请稍后重试');}
        try{$again=MicroVideoWorkbenchRecords::table('asset')->where($where)->find();if($again){try{return self::sourceAsset($tenant,$user,(string)MicroVideoStore::json($again['image_json'])['uri'],$type);}catch(\Throwable){}}
            $video=$type===FileEnum::VIDEO_TYPE;$bytes=self::bytes($tenant,$source,$video?268435456:10485760);
            if($video){$extension=strtolower(pathinfo((string)$source['uri'],PATHINFO_EXTENSION));$stored=AigcVideoAssetService::persistGeneratedVideo('data:video/'.(in_array($extension,['mp4','webm','mov','m4v'],true)?$extension:'mp4').';base64,'.base64_encode($bytes),$tenant,$user);}
            else{$info=@getimagesizefromstring($bytes);if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp','image/gif'],true))throw new InvalidArgumentException('源素材不是可用图片');$stored=AigcImageAssetService::persistGeneratedImage('data:'.$info['mime'].';base64,'.base64_encode($bytes),$tenant,$user);}
            $img=self::sourceAsset($tenant,$user,(string)$stored['uri'],$type);$values=['image_json'=>MicroVideoStore::encode($img),'update_time'=>time()];if($again)MicroVideoWorkbenchRecords::table('asset')->where('id',$again['id'])->update($values);else MicroVideoWorkbenchRecords::table('asset')->insert($where+$values+['create_time'=>time()]);return $img;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function bytes(int $tenant,array $source,int $maxBytes=10485760): string
    {
        $uri=ltrim((string)$source['uri'],'/');if(preg_match('#(^|/)\.{1,2}(/|$)|[\\\\%\x00-\x20]#',$uri))throw new InvalidArgumentException('源素材路径无效');
        if(($source['storage_engine']??'')==='local'){$root=realpath(public_path());$path=realpath(public_path().$uri);if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)||filesize($path)>$maxBytes)throw new InvalidArgumentException('源素材缺失或超过大小限制，请重新上传');$bytes=file_get_contents($path);}
        else{$url=StorageSignedUrlService::resolve($uri,(string)$source['storage_scope'],(string)$source['storage_engine'],(string)$source['storage_domain'],$tenant);$url=(string)$url;$response=OpenSafeHttp::get($url,$maxBytes);$bytes=(string)$response['body'];}
        if($bytes===''||strlen($bytes)>$maxBytes)throw new InvalidArgumentException('源素材读取失败或超过大小限制');return $bytes;
    }
}
