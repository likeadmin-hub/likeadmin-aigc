<?php
// Isolated process: fake only source authority/delivery; exercise actual export embedding.
namespace app\common\service\decorate\agent {class DecorationAgentAssetReferences {public static function captureIdentities($page,$tenant){return ['/uploads/private/fixture.png'=>['image_uri'=>'uploads/private/fixture.png','storage_scope'=>'tenant','storage_engine'=>'qiniu','storage_domain'=>'https://private.example.test']];}}}
namespace app\common\service {class FileService {public static function getFileUrlByStorage(...$args){return 'https://private.example.test/uploads/private/fixture.png?e=1&token=fixture';}}}
namespace app\common\service\storage {class StorageConfigService {public static function getStoredFileConfig(...$args){return ['engine'=>['qiniu'=>['private_access'=>true]]];}}}
namespace app\common\service\open_platform {class OpenSafeHttp {public static int $gets=0;public static function get(...$args){++self::$gets;return ['body'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=')];}}}
namespace {
use app\common\service\decorate\DecorationPortableAssets as Assets;
require_once dirname(__DIR__,2).'/app/common/service/decorate/DecorationPortableAssets.php';
$widget=['name'=>'card','styles'=>['background'=>'url(/uploads/private/fixture.png)'],'content'=>['html'=>'<img src="/uploads/private/fixture.png">']];
$out=Assets::export(['template'=>['cover'=>'/uploads/private/fixture.png','settings'=>['mobile_tabbar'=>['style'=>['icon_atlas'=>'/uploads/private/fixture.png']]]],'pages'=>[['data'=>json_encode([$widget]),'meta'=>'[]']]],7);
$items=$out['resources']['items'];if(count($items)!==1||count($out['files'])!==1||app\common\service\open_platform\OpenSafeHttp::$gets!==1)throw new RuntimeException('Private CSS/HTML/settings/cover must share one embedded object');
$item=$items[0];if(!hash_equals($item['sha256'],hash('sha256',$out['files'][$item['entry']]))||$item['size']!==strlen($out['files'][$item['entry']]))throw new RuntimeException('Private entry integrity missing');
if(!str_starts_with($out['payload']['template']['cover'],'https://'))throw new RuntimeException('Private JSON is still a full URL');
echo "PASS private-source export embedding: 3 checks; offline delivery fixture\n";
}
