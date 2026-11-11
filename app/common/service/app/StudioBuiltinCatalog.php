<?php
declare(strict_types=1);
namespace app\common\service\app;

use app\common\service\app\aigc_product_video\ProductVideoBuiltinCatalog as ProductCatalog;
use InvalidArgumentException;

/** Portable, reviewed source definitions. No credentials, local upload IDs or media binaries. */
final class StudioBuiltinCatalog
{
    public const APPS=['aigc_motion_sync','aigc_story_commerce'];
    private static array $directories=[];
    private static array $memo=[];
    public static function assertApp(string $app): void {if(!in_array($app,self::APPS,true))throw new InvalidArgumentException('应用不支持此内置模板入口');}
    public static function directory(string $app): string {self::assertApp($app);return self::$directories[$app]??base_path().'apps/'.$app.'/data/inspirations';}
    public static function withDirectory(string $app,string $dir,callable $call): mixed
    {
        self::assertApp($app);if(PHP_SAPI!=='cli')throw new InvalidArgumentException('目录仅允许在控制台测试中指定');
        $old=self::$directories;self::$directories[$app]=$dir;self::$memo=[];
        try{return $call();}finally{self::$directories=$old;self::$memo=[];}
    }
    public static function publicAsset(array $asset): array
    {
        $out=ProductCatalog::publicAsset($asset);
        if(in_array($asset['type']??'',['image','video'],true))$out['type']=$asset['type'];
        foreach(['mime_type','file_size','width','height','duration'] as $key)if(isset($asset[$key]))$out[$key]=$asset[$key];
        foreach(['file_size','width','height','duration'] as $key)if(isset($out[$key])&&(!is_numeric($out[$key])||!is_finite((float)$out[$key])||(float)$out[$key]<0))throw new InvalidArgumentException('内置素材信息无效');
        return $out;
    }
    public static function validate(string $app,array $data): array
    {
        self::assertApp($app);
        if(($data['schema_version']??0)!==1||($data['app_code']??'')!==$app||(int)($data['version']??0)<1)throw new InvalidArgumentException('内置模板定义不匹配');
        $identity=array_intersect_key((array)($data['model_identity']??[]),array_flip(['source_code','upstream_resource_key','resource_type','upstream_app_code']));
        if(empty($identity['source_code'])||empty($identity['upstream_resource_key']))throw new InvalidArgumentException('内置模板缺少稳定模型标识');
        foreach($identity as $v)if(!is_string($v)||strlen($v)>191)throw new InvalidArgumentException('内置模型标识无效');
        $body=StudioBuiltinTemplates::portableBody($app,(array)($data['configuration']??[]));
        if(trim((string)($data['title']??''))==='')throw new InvalidArgumentException('请填写内置模板名称');
        return ['schema_version'=>1,'app_code'=>$app,'key'=>ProductCatalog::key((string)($data['key']??'')),'version'=>(int)$data['version'],
            'title'=>\app\common\service\app\aigc_story_commerce\StoryCommercePolicy::text($data['title']??'',100),
            'status'=>empty($data['status'])?0:1,'sort'=>max(0,min(9999,(int)($data['sort']??0))),'deleted'=>!empty($data['deleted']),
            'model_identity'=>$identity,'configuration'=>$body,'media'=>self::publicAsset(array_replace((array)($data['media']??[]),['type'=>'video'])),
            'retained_media'=>array_map([self::class,'publicAsset'],(array)($data['retained_media']??[]))];
    }
    public static function all(string $app): array
    {
        $files=glob(self::directory($app).'/*.json')?:[];sort($files);if(count($files)>500)throw new InvalidArgumentException('内置模板数量超出限制');
        $stamp=[];$total=0;foreach($files as $f){clearstatcache(true,$f);$size=filesize($f);if(is_link($f)||$size>196608)throw new InvalidArgumentException('内置模板文件无效');$total+=$size;$stamp[]=[$f,filemtime($f),fileinode($f),$size];}
        if($total>16777216)throw new InvalidArgumentException('内置配置目录过大');$hash=hash('sha256',json_encode($stamp));
        if(isset(self::$memo[$app][$hash]))return self::$memo[$app][$hash];
        $all=[];foreach($files as $f){$entry=self::validate($app,json_decode(file_get_contents($f),true,64,JSON_THROW_ON_ERROR));if(basename($f)!==$entry['key'].'.json'||isset($all[$entry['key']]))throw new InvalidArgumentException('内置模板文件标识冲突');$all[$entry['key']]=$entry;}
        self::$memo[$app]=[$hash=>$all];return $all;
    }
    public static function assets(string $app,array $entry): array
    {
        $b=$entry['configuration'];return array_merge($app==='aigc_motion_sync'?[$b['image'],$b['video']]:$b['images'],[$entry['media']],$entry['retained_media']??[]);
    }
    public static function asset(string $uri,string $engine,string $domain): ?array
    {
        if(!in_array($engine,['aliyun','qcloud','qiniu'],true)||$domain==='')return null;
        foreach(self::APPS as $app)foreach(self::all($app) as $entry)foreach(self::assets($app,$entry) as $a)
            if($a['uri']===ltrim($uri,'/')&&$a['storage_engine']===$engine&&$a['storage_domain']===rtrim($domain,'/'))return $a;
        return null;
    }
    public static function mediaUrl(string $uri,string $engine,string $domain): ?string
    { $a=self::asset($uri,$engine,$domain);return $a?$a['storage_domain'].'/'.$a['uri']:null; }
    public static function write(string $app,array $data): array
    {
        $data=self::validate($app,$data);$dir=self::directory($app);
        if(!is_dir($dir)||is_link($dir)||!is_writable($dir))throw new InvalidArgumentException('内置模板目录不可写，请联系维护人员');
        $old=self::all($app)[$data['key']]??[];$pins=[];
        foreach(array_merge($old?self::assets($app,$old):[],self::assets($app,$data)) as $a){$a=self::publicAsset($a);$pins[$a['storage_engine'].'|'.$a['storage_domain'].'|'.$a['uri']]=$a;}
        $data['retained_media']=array_values($pins);$text=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if(strlen($text)>196608)throw new InvalidArgumentException('内置模板配置过大');$path=$dir.'/'.$data['key'].'.json';if(is_link($path))throw new InvalidArgumentException('内置模板文件无效');
        $tmp=tempnam($dir,'.inspiration-');try{if(!$tmp||file_put_contents($tmp,$text,LOCK_EX)!==strlen($text)||!chmod($tmp,0644)||!rename($tmp,$path))throw new InvalidArgumentException('内置模板保存失败，原定义已保留');}
        finally{if($tmp&&is_file($tmp))unlink($tmp);unset(self::$memo[$app]);}
        return $data;
    }
}
