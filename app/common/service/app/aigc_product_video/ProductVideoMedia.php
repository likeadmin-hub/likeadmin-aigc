<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\ai\StudioMediaInputService;
use InvalidArgumentException;
final class ProductVideoMedia
{
    public static function import(int $tenant,int $user,string $url): array
    {
        ProductVideoService::available($tenant,$user);
        $url=ProductVideoPolicy::text($url,2000);
        try { $response=OpenSafeHttp::get($url,33554432,25); }
        catch(\app\common\service\open_platform\OpenException $e) { throw new InvalidArgumentException($e->getMessage(),0,$e); }
        $type=strtolower((string)($response['headers']['content-type']??''));
        if(str_contains($type,'text/')||str_contains($type,'json'))throw new InvalidArgumentException('该地址是分享页面，请上传视频文件或粘贴可下载的视频链接');
        $dir=runtime_path().'product_video_import/';if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.bin2hex(random_bytes(16)).'.mp4';
        try{file_put_contents($path,$response['body']);$stored=AigcVideoAssetService::persistGeneratedVideoFile($path,$tenant,$user);return ProductVideoService::publicBody(StudioMediaInputService::validateVideo($tenant,$user,$stored['uri']),$tenant);}
        finally{if(is_file($path))unlink($path);}
    }
}
