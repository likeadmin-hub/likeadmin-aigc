<?php
// Isolated adapter exercise: no framework/database/network/provider is initialized.
namespace app\common\enum { final class FileEnum {const SOURCE_ADMIN=0;const IMAGE_TYPE=10;} }
namespace app\common\model\file {
    final class TenantFile {
        public static array $rows=[];
        public static function where(array $where): object {return new class($where){
            private array $where;
            public function __construct(array $where){$this->where=$where;}
            public function findOrEmpty(): object {$row=[];foreach(TenantFile::$rows as $r)if(!array_diff_assoc($this->where,$r)){$row=$r;break;}return new class($row){private array $row;public function __construct(array $row){$this->row=$row;}public function isEmpty():bool{return !$this->row;}public function toArray():array{return $this->row;}};}
        };}
    }
}
namespace app\common\service { final class FileService {public static function getFileUrlByStorage(string $uri,string $scope,string $engine,string $domain):string{return 'https://owned.test/'.$uri;}} }
namespace app\common\service\storage { final class StorageSignedUrlService {public static function resolve(...$args):?string{return null;}} }
namespace {
    $root=sys_get_temp_dir().'/decoration-media-shim-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/uploads');
    function public_path():string{return $GLOBALS['root'].'/';}
    require dirname(__DIR__,2).'/app/common/service/decorate/agent/DecorationAgentMediaService.php';
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=');file_put_contents($root.'/uploads/owned.png',$png);
    \app\common\model\file\TenantFile::$rows=[['id'=>1,'tenant_id'=>7,'source'=>0,'type'=>10,'uri'=>'uploads/owned.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''],['id'=>2,'tenant_id'=>8,'source'=>0,'type'=>10,'uri'=>'uploads/owned.png'],['id'=>3,'tenant_id'=>7,'source'=>1,'type'=>10,'uri'=>'uploads/owned.png']];
    $result=[];
    try {
        $media=\app\common\service\decorate\agent\DecorationAgentMediaService::class;
        try {$result['attachments']=$media::validateAttachments(7,[['file_id'=>1,'url'=>'https://evil.test/forged.png','uses'=>['asset','layout','style','generation']]]);}catch(\Throwable $e){$result['attachment_error']=$e->getMessage();}
        foreach([2,3] as $id){try{$media::validateAttachments(7,[['file_id'=>$id,'uses'=>['asset']]]);$result['rejected'][$id]=false;}catch(\Throwable $e){$result['rejected'][$id]=true;}}
        $method=new \ReflectionMethod($media,'referenceRequest');$method->setAccessible(true);
        foreach(['none'=>['reference_file_ids'=>[]],'one'=>['reference_file_ids'=>[1]],'bad'=>['reference_file_ids'=>[2]]] as $key=>$refs){
            try{$result['requests'][$key]=$method->invoke(null,7,['attachments'=>[['file_id'=>1,'uses'=>['generation']]],'reference_images'=>['https://owned.test/expired-signature.png'],'provider_params'=>['input'=>['image'=>'https://evil.test']]]+$refs);}catch(\Throwable $e){$result['request_errors'][$key]=$e->getMessage();}
        }
    }finally{unlink($root.'/uploads/owned.png');rmdir($root.'/uploads');rmdir($root);}
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
