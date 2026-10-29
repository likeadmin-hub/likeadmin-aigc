<?php
// Isolated source/delivery doubles; actual traversal, validation and export bookkeeping.
namespace app\common\service\decorate\agent {
    class DecorationAgentAssetReferences {
        public static function captureIdentities($page,$tenant,$onInvalid=null) {
            if (!$onInvalid) throw new \InvalidArgumentException('invalid identity');
            $onInvalid('/private/denied.png?token=secret',new \InvalidArgumentException('invalid identity'));
            $result=[];
            foreach(['ok','dead','badbytes'] as $name) $result['/private/'.$name.'.png']=['image_uri'=>'private/'.$name.'.png','storage_scope'=>'tenant','storage_engine'=>'qiniu','storage_domain'=>'https://private.example.test'];
            return $result;
        }
    }
}
namespace app\common\service {
    class FileService {
        public static function getFileUrlByStorage($uri,...$args) {
            if (str_contains($uri,'denied')) throw new \LogicException('Denied identity must never be signed');
            return 'https://private.example.test/'.$uri.'?token=secret';
        }
    }
}
namespace app\common\service\storage {
    class StorageConfigService { public static function getStoredFileConfig(...$args) { return ['engine'=>['qiniu'=>['private_access'=>true]]]; } }
}
namespace app\common\service\open_platform {
    class OpenSafeHttp {
        public static array $gets=[];
        public static function get($url,...$args) {
            self::$gets[]=$url;
            if (str_contains($url,'dead')) throw new \RuntimeException('素材地址返回 HTTP 404');
            if (str_contains($url,'badbytes')) return ['body'=>'<html>expired</html>'];
            return ['body'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=')];
        }
    }
}
namespace {
    use app\common\service\decorate\DecorationPortableAssets as Assets;
    $root=dirname(__DIR__,2);
    require $root.'/app/common/service/decorate/DecorationPortableAssets.php';
    $payload=['pages'=>[['name'=>'Customer service','page_code'=>'service','data'=>json_encode([['content'=>['image'=>'/private/denied.png?token=secret','images'=>['/private/ok.png','/private/dead.png','/private/badbytes.png','/private/dead.png']]]])]]];
    $out=Assets::export($payload,1,null,true);
    if(count($out['warnings'])!==3||$out['resources']['count']!==1||count($out['files'])!==1)throw new \RuntimeException('Valid assets or failed resource bookkeeping regressed');
    if(count($out['warnings'][1]['locations'])!==2)throw new \RuntimeException('Repeated failed asset lost locations');
    if(str_contains(json_encode($out['warnings']),'secret'))throw new \RuntimeException('Warning report leaked signed query');
    $body=json_decode($out['payload']['pages'][0]['data'],true)[0]['content'];
    if($body['image']!==''||$body['images'][1]!==''||$body['images'][2]!==''||!str_starts_with($body['images'][0],'https://'))throw new \RuntimeException('Export copy did not blank only rejected assets');
    if(count(app\common\service\open_platform\OpenSafeHttp::$gets)!==3)throw new \RuntimeException('Denied/repeated assets caused unsafe extra fetches');
    try { Assets::export($payload,1); throw new \LogicException('Strict callers changed behavior'); }
    catch (\InvalidArgumentException $expected) { }
    echo "PASS export warnings: valid files retained, failed entries atomic, locations deduplicated, secrets redacted, denied identities unsigned, strict callers preserved.\n";
}
