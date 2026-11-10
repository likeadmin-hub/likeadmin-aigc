<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;

use InvalidArgumentException;

/** Authored JSON only. Media stays in its original, explicitly public object storage. */
final class ProductVideoBuiltinCatalog
{
    private static ?string $testDirectory=null;
    private static array $memo=[];
    private static float $checkedAt=0;
    private static ?array $assets=null;
    public static function refresh(): void {self::$memo=[];self::$assets=null;self::$checkedAt=0;}
    public static function directory(): string {return self::$testDirectory??base_path().'apps/aigc_product_video/data/inspirations';}
    public static function withDirectory(string $directory,callable $call): mixed
    {
        if(PHP_SAPI!=='cli')throw new InvalidArgumentException('目录仅允许在控制台测试中指定');
        $old=self::$testDirectory;self::$testDirectory=$directory;self::refresh();
        try{return $call();}finally{self::$testDirectory=$old;self::refresh();}
    }
    public static function key(string $key): string
    {if(!preg_match('/^builtin-[a-f0-9]{24}$/D',$key))throw new InvalidArgumentException('内置模板标识无效');return $key;}
    public static function publicAsset(array $asset): array
    {
        $uri=ltrim((string)($asset['uri']??$asset['video_uri']??''),'/');$engine=(string)($asset['storage_engine']??'');$domain=rtrim((string)($asset['storage_domain']??''),'/');
        if(!in_array($engine,['aliyun','qcloud','qiniu'],true)||$uri===''||preg_match('~(?:^[a-z]+:|[?\x00-\x20\\\\#]|(?:^|/)\.\.(?:/|$))~i',$uri))throw new InvalidArgumentException('内置模板需要已持久化的公开对象存储素材');
        \app\common\service\app\aigc_canvas\CanvasBuiltinPresetService::publicUrl($domain.'/'.$uri);
        $out=array_intersect_key($asset,array_flip(['name','role','reference_slot_id','mention_name','duration','width','height']));
        return ['uri'=>$uri,'storage_scope'=>'platform','storage_engine'=>$engine,'storage_domain'=>$domain]+$out;
    }
    public static function validate(array $data): array
    {
        if(($data['schema_version']??0)!==1||(int)($data['version']??0)<1)throw new InvalidArgumentException('内置模板结构无效');
        $body=ProductVideoTemplates::snapshot((array)($data['configuration']??[]));
        $body['images']=array_map([self::class,'publicAsset'],$body['images']);
        if(!$body['images']||$body['prompt']==='')throw new InvalidArgumentException('内置模板缺少商品素材或视频描述');
        $identity=array_intersect_key((array)($data['model_identity']??[]),array_flip(['source_code','upstream_resource_key','resource_type','upstream_app_code']));
        if(empty($identity['source_code'])||empty($identity['upstream_resource_key']))throw new InvalidArgumentException('内置模板缺少算力市场模型标识');
        foreach($identity as $value)if(!is_string($value)||strlen($value)>191)throw new InvalidArgumentException('内置模型标识无效');
        return ['schema_version'=>1,'key'=>self::key((string)($data['key']??'')),'version'=>(int)$data['version'],'title'=>ProductVideoPolicy::text($data['title']??'',100),
            'status'=>empty($data['status'])?0:1,'deleted'=>!empty($data['deleted']),'sort'=>max(0,min(9999,(int)($data['sort']??0))),
            'model_name'=>ProductVideoPolicy::text($data['model_name']??'',160),'model_identity'=>$identity,'configuration'=>$body,'media'=>self::publicAsset((array)($data['media']??[])),
            'retained_media'=>array_map([self::class,'publicAsset'],(array)($data['retained_media']??[]))];
    }
    public static function all(): array
    {
        // One filesystem scan serves all gallery URLs in a request; long workers recheck updates.
        if(self::$memo&&microtime(true)-self::$checkedAt<1)return reset(self::$memo);
        self::$checkedAt=microtime(true);
        $files=glob(self::directory().'/*.json')?:[];sort($files);if(count($files)>500)throw new InvalidArgumentException('内置模板目录超出数量限制');
        $stamp=[];$bytes=0;foreach($files as $file){clearstatcache(true,$file);$size=filesize($file);if(is_link($file)||$size>196608)throw new InvalidArgumentException('内置模板文件无效');$bytes+=$size;$stamp[]=[$file,filemtime($file),fileinode($file),$size];}
        if($bytes>16777216)throw new InvalidArgumentException('内置配置目录过大');
        $key=hash('sha256',json_encode($stamp));if(isset(self::$memo[$key]))return self::$memo[$key];
        $all=[];foreach($files as $file){$data=self::validate(json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR));if(basename($file)!==$data['key'].'.json'||isset($all[$data['key']]))throw new InvalidArgumentException('内置模板文件标识冲突');$all[$data['key']]=$data;}
        self::$assets=null;self::$memo=[$key=>$all];return $all;
    }
    public static function write(array $data): array
    {
        self::refresh();$data=self::validate($data);$dir=self::directory();if(!is_dir($dir)||is_link($dir)||!is_writable($dir))throw new InvalidArgumentException('内置模板目录不可写，请联系安装维护者检查权限');
        $path=$dir.'/'.$data['key'].'.json';if(is_link($path))throw new InvalidArgumentException('内置模板文件无效');
        // Older drafts can continue using references removed from a newer template revision.
        $old=self::all()[$data['key']]??[];$pins=[];
        foreach(array_merge($old['retained_media']??[],$old['configuration']['images']??[],isset($old['media'])?[$old['media']]:[],$data['retained_media'],$data['configuration']['images'],[$data['media']]) as $asset){
            $asset=self::publicAsset($asset);$pins[hash('sha256',$asset['storage_engine'].'|'.$asset['storage_domain'].'|'.$asset['uri'])]=$asset;
        }
        $data['retained_media']=array_values($pins);
        $text=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(strlen($text)>196608)throw new InvalidArgumentException('内置模板配置过大');$temp=tempnam($dir,'.inspiration-');
        try{if($temp===false||file_put_contents($temp,$text,LOCK_EX)!==strlen($text)||!chmod($temp,0644)||!rename($temp,$path))throw new InvalidArgumentException('内置模板保存失败，原定义已保留');}
        finally{if($temp&&is_file($temp))unlink($temp);self::refresh();}
        return $data;
    }
    /** Approved platform references bypass tenant credentials; no signed URL is persisted. */
    public static function mediaUrl(string $uri,string $engine,string $domain): ?string
    {
        if(!in_array($engine,['aliyun','qcloud','qiniu'],true)||$domain==='')return null;
        $all=self::all();
        if(self::$assets===null){
            self::$assets=[];
            foreach($all as $entry)foreach(array_merge($entry['configuration']['images'],[$entry['media']],$entry['retained_media']) as $asset)
                self::$assets[$asset['storage_engine'].'|'.$asset['storage_domain'].'|'.$asset['uri']]=$asset['storage_domain'].'/'.$asset['uri'];
        }
        return self::$assets[$engine.'|'.rtrim($domain,'/').'|'.ltrim($uri,'/')]??null;
    }
}
