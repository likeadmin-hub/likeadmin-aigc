<?php
namespace app\common\service\storage {
    final class StorageSignedUrlService {public static function resolve(...$args):string{return 'https://owned.example.test/fresh-signature';}}
}
namespace app\common\service {
    final class FileService {public static function format(...$args):string{return '/fallback';}}
}
namespace {
    require dirname(__DIR__).'/vendor/autoload.php';
    $asset=['storage_engine'=>'aliyun','storage_scope'=>'tenant','storage_domain'=>'https://owned.example.test','tenant_id'=>1,'user_id'=>2,'file_id'=>3];
    $body=['videos'=>[['video_uri'=>'uploads/own.mp4','video_url'=>'https://owned.example.test/expired']+$asset],'images'=>[['image_uri'=>'uploads/own.png','image_url'=>'https://owned.example.test/expired']+$asset]];
    $out=\app\common\service\app\aigc_product_video\ProductVideoService::publicBody($body,1);
    foreach(['videos'=>'video_url','images'=>'image_url'] as $kind=>$key){$v=$out[$kind][0];if($v[$key]!=='https://owned.example.test/fresh-signature'||$v['url']!==$v[$key]||isset($v['tenant_id'])||isset($v['user_id'])||isset($v['file_id']))throw new \RuntimeException('Stored media response must refresh every display URL without private ownership fields');}
    echo "PASS product video stored-result signature rotation and public ownership projection\n";
}
