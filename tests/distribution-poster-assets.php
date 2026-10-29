<?php
declare(strict_types=1);
// Only in-memory receipts and local raster fixtures. Never reads credentials or cloud objects.
namespace app\common\service\storage {
    class StorageConfigService {
        public static array $config = ['scope'=>'tenant','default'=>'qcloud','engine'=>['qcloud'=>['region'=>'ap-chongqing','bucket'=>'fixture-1234567890','access_key'=>'fixture-access','secret_key'=>'fixture-secret','domain'=>'https://fixture-1234567890.cos.ap-chongqing.myqcloud.com','private_access'=>true]]];
        public static function getStoredFileConfig(...$args): array { return self::$config; }
        public static function getEffectiveConfig(...$args): array { return self::$config; }
    }
}
namespace app\common\service\distribution {
    class DistributionService {
        public static bool $enabled=true, $frozen=false;
        public static function assertEnabled(int $tenant): void { if (!self::$enabled || $tenant!==7) throw new \RuntimeException('分销功能未开启'); }
        public static function ensurePromoter(...$args): array { return ['is_frozen'=>self::$frozen,'invite_code'=>'OWN7']; }
    }
}
namespace PosterAssetFixture {
    class Record extends \ArrayObject { public function isEmpty(): bool{return !$this->count();} public function toArray(): array{return $this->getArrayCopy();} public function getData($key=null){return $key===null?$this->toArray():($this[$key]??null);} }
    class Query {
        private array $filters=[];
        public function __construct(private string $table) {}
        public function where($filters,$value=null): self { $this->filters+=is_array($filters)?$filters:[$filters=>$value];return $this; }
        public function lock(...$args): self{return $this;}
        public function find(): ?array {foreach(Data::$tables[$this->table]??[] as $row){foreach($this->filters as $key=>$value)if(($row[$key]??null)!=$value)continue 2;return $row;}return null;}
        public function findOrEmpty(): Record {return new Record($this->find()??[]);}
    }
    class Data { public static array $tables=[]; }
    class Stream {private int $offset=0;public function __construct(private string $body){}public function read(int $length):string{$result=substr($this->body,$this->offset,$length);$this->offset+=strlen($result);return $result;}public function eof():bool{return $this->offset>=strlen($this->body);}}
    class Response {public function __construct(private int $length,private string $range=''){}public function getHeaderLine($name):string{return strtolower($name)==='content-length'?(string)$this->length:(strtolower($name)==='content-range'?$this->range:'');}}
}
namespace think\facade { class Db {public static function name(string $name): \PosterAssetFixture\Query{return new \PosterAssetFixture\Query($name);}} }
namespace app\common\model\file {class TenantFile {public static function where($filters):\PosterAssetFixture\Query{return (new \PosterAssetFixture\Query('tenant_file'))->where($filters);}}}
namespace app\common\model\user {class User {public static function where($filters):\PosterAssetFixture\Query{return (new \PosterAssetFixture\Query('user'))->where($filters);}}}
namespace app\common\service {class FileService {public static function getFileUrlByStorage($uri,...$args):string{return 'https://fixture-1234567890.cos.ap-chongqing.myqcloud.com/'.$uri;}}}
namespace Qcloud\Cos {
    class Client {
        public static array $configs=[], $calls=[]; public static string $body='', $range=''; public static ?int $length=null; public static bool $fail=false;
        public function __construct(array $config){self::$configs[]=$config;}
        public function getObject(array $args):array{
            self::$calls[]=$args;
            if(self::$fail)throw new \RuntimeException('fixture-secret transport path');
            if(isset($args['@http']['on_headers']))$args['@http']['on_headers'](new \PosterAssetFixture\Response(self::$length??strlen(self::$body),self::$range));
            return ['Body'=>new \PosterAssetFixture\Stream(self::$body),'ContentLength'=>self::$length??strlen(self::$body)];
        }
    }
}
namespace {
    use PosterAssetFixture\Data;
    use Qcloud\Cos\Client;
    use app\common\service\distribution\{PosterService, PosterRasterService, DistributionService};
    use app\common\service\storage\StorageConfigService as Config;
    $fixtureDir=sys_get_temp_dir().'/poster-raster-'.bin2hex(random_bytes(4));mkdir($fixtureDir.'/uploads',0700,true);
    function public_path():string {return $GLOBALS['fixtureDir'].'/';}
    require dirname(__DIR__).'/app/common/service/storage/StorageOperationTimeout.php';
    require dirname(__DIR__).'/app/common/service/distribution/PosterRasterService.php';
    require dirname(__DIR__).'/app/common/service/distribution/PosterService.php';
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=');
    Client::$body=$png;
    $layout=['canvas'=>['width'=>1080,'height'=>1440,'background_asset_id'=>5],'layers'=>[]];
    Data::$tables=[
        'distribution_relation'=>[['tenant_id'=>7,'user_id'=>9,'invite_code'=>'OWN7','is_frozen'=>0]],
        'distribution_poster_template'=>[['id'=>2,'tenant_id'=>7,'delete_time'=>0,'status'=>1,'published_version'=>11]],
        'distribution_poster_version'=>[['id'=>11,'template_id'=>2,'tenant_id'=>7,'name'=>'Fixture','layout_json'=>json_encode($layout)]],
        'tenant_file'=>[['id'=>5,'tenant_id'=>7,'type'=>10,'source'=>0,'source_id'=>0,'uri'=>'uploads/background.png','storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://fixture-1234567890.cos.ap-chongqing.myqcloud.com']],
        'user'=>[['id'=>9,'tenant_id'=>7,'nickname'=>'本人','avatar'=>'uploads/avatar.png','is_disable'=>0]]
    ];
    $checks=0;
    function check($ok,$message):void {if(!$ok)throw new \RuntimeException($message);$GLOBALS['checks']++;}
    function rejected(callable $fn,string $message=''):void {try{$fn();}catch(\Throwable $e){check($message===''||str_contains($e->getMessage(),$message),'Unexpected rejection:'.$e->getMessage());return;}throw new \RuntimeException('Expected rejection');}
    $input=['id'=>2,'published_version'=>11];
    $result=PosterService::assets(7,9,$input);
    check($result['background']['base64']===base64_encode($png)&&$result['background']['mime']==='image/png','Raster bytes missing');
    check($result['avatar']===null&&$result['profile']['invite_code']==='OWN7'&&$result['published_version']===11,'Own profile / absent avatar fallback');
    check(Client::$calls[0]['Bucket']==='fixture-1234567890'&&Client::$calls[0]['Key']==='uploads/background.png','Receipt bucket/key authority lost');
    check(Client::$configs[0]['scheme']==='https'&&Client::$configs[0]['retry']===0&&Client::$configs[0]['allow_redirects']===false,'HTTPS/retry policy missing');
    check(Client::$configs[0]['timeout']<=5&&Client::$calls[0]['@http']['verify']===true&&isset(Client::$calls[0]['Range']),'Bounded private SDK reads required');
    check(!str_contains(json_encode($result),'fixture-secret')&&!str_contains(json_encode($result),'fixture-access'),'Credentials leaked');
    rejected(fn()=>PosterService::assets(8,9,$input));
    rejected(fn()=>PosterService::assets(7,10,$input));
    rejected(fn()=>PosterService::assets(7,9,['id'=>2,'published_version'=>10]),'海报已更新');
    rejected(fn()=>PosterService::assets(7,9,$input+['url'=>'http://127.0.0.1/secret']));
    DistributionService::$enabled=false;rejected(fn()=>PosterService::assets(7,9,$input));DistributionService::$enabled=true;
    Data::$tables['distribution_relation'][0]['is_frozen']=1;rejected(fn()=>PosterService::assets(7,9,$input));Data::$tables['distribution_relation'][0]['is_frozen']=0;
    $relation=Data::$tables['distribution_relation'];Data::$tables['distribution_relation']=[];rejected(fn()=>PosterService::assets(7,9,$input));Data::$tables['distribution_relation']=$relation;
    Data::$tables['distribution_poster_template'][0]['status']=0;rejected(fn()=>PosterService::assets(7,9,$input));Data::$tables['distribution_poster_template'][0]['status']=1;
    Data::$tables['tenant_file'][0]['tenant_id']=8;rejected(fn()=>PosterService::assets(7,9,$input));Data::$tables['tenant_file'][0]['tenant_id']=7;
    $receipt=Data::$tables['tenant_file'][0];
    foreach(['../.env','uploads/../.env','uploads/%2e%2e/.env','file:///etc/passwd','https://127.0.0.1/uploads/x.png','https://foreign.test/uploads/x.png','uploads/a\\b.png','uploads/a?x=1','uploads//a.png','//uploads/a.png'] as $uri){$bad=$receipt;$bad['uri']=$uri;rejected(fn()=>PosterRasterService::read($bad,7,microtime(true)+4));}
    $bad=$receipt;$bad['uri']='https://fixture-1234567890.cos.ap-chongqing.myqcloud.com/uploads/background.png';check(PosterRasterService::read($bad,7,microtime(true)+4)['base64']===base64_encode($png),'Trusted absolute receipt failed');
    $before=count(Client::$calls);$bad=$receipt;$bad['tenant_id']=8;rejected(fn()=>PosterRasterService::read($bad,7,microtime(true)+4));check(count(Client::$calls)===$before,'Foreign tenant reached cloud');
    $old=Config::$config;Config::$config['scope']='platform';rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)+4));Config::$config=$old;
    foreach(['region'=>'127.0.0.1','bucket'=>'fixture.example.test/path'] as $key=>$value){Config::$config['engine']['qcloud'][$key]=$value;rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)+4));Config::$config=$old;}
    Client::$length=PosterRasterService::MAX_BYTES+1;rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)+4));Client::$length=null;
    Client::$range='bytes 0-10/999999999';rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)+4));Client::$range='';
    Client::$length=1;Client::$body=str_repeat('X',PosterRasterService::MAX_BYTES+1);rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)+4));Client::$length=null;Client::$body=$png;
    rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)-1));
    rejected(fn()=>PosterService::assets(7,9,['id'=>[], 'published_version'=>11]));
    Client::$body='<svg><script>alert(1)</script></svg>';rejected(fn()=>PosterRasterService::read($receipt,7,microtime(true)+4));Client::$body=$png;
    Client::$fail=true;try{PosterRasterService::read($receipt,7,microtime(true)+4);}catch(\Throwable $e){check(!str_contains($e->getMessage(),'fixture-secret'),'Raw SDK secrets escaped');}Client::$fail=false;
    $ownAvatar=$receipt;$ownAvatar['id']=6;$ownAvatar['source']=1;$ownAvatar['source_id']=9;$ownAvatar['uri']='uploads/avatar.png';Data::$tables['tenant_file'][]=$ownAvatar;
    check(PosterService::assets(7,9,$input)['avatar']['base64']===base64_encode($png),'Own receipt avatar missing');
    Data::$tables['tenant_file'][1]['uri']='uploads/../outside.png';Data::$tables['user'][0]['avatar']='uploads/../outside.png';check(PosterService::assets(7,9,$input)['avatar']===null,'Unreadable own avatar must not break background');Data::$tables['tenant_file'][1]['uri']='uploads/avatar.png';Data::$tables['user'][0]['avatar']='uploads/avatar.png';
    Data::$tables['tenant_file'][1]['source_id']=10;check(PosterService::assets(7,9,$input)['avatar']===null,'Another users avatar receipt accepted');
    Config::$config=['scope'=>'tenant','default'=>'local','engine'=>['local'=>[]]];
    $local=$receipt;$local['storage_engine']='local';$local['uri']='uploads/fixture.png';file_put_contents($fixtureDir.'/uploads/fixture.png',$png);
    check(PosterRasterService::read($local,7,microtime(true)+4)['base64']===base64_encode($png),'Local receipt failed');
    file_put_contents($fixtureDir.'/outside.png',$png);symlink($fixtureDir.'/outside.png',$fixtureDir.'/uploads/escape.png');$local['uri']='uploads/escape.png';rejected(fn()=>PosterRasterService::read($local,7,microtime(true)+4));
    mkdir($fixtureDir.'/resource/distribution-posters',0700,true);
    $builtin=$receipt;$builtin['uri']='resource/distribution-posters/nocturne.png';$builtin['storage_scope']='platform';$builtin['storage_engine']='local';$builtin['storage_domain']='';
    file_put_contents($fixtureDir.'/'.$builtin['uri'],$png);
    check(PosterRasterService::read($builtin,7,microtime(true)+4)['base64']===base64_encode($png),'Shipped builtin receipt failed');
    foreach(['tenant_id'=>8,'source'=>1,'source_id'=>9,'storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://foreign.test'] as $key=>$value){$bad=$builtin;$bad[$key]=$value;rejected(fn()=>PosterRasterService::read($bad,7,microtime(true)+4));}
    foreach(['resource/distribution-posters/../../.env','resource/distribution-posters/unknown.png','resource/distribution-posters/%6eocturne.png','/resource/distribution-posters/nocturne.png'] as $uri){$bad=$builtin;$bad['uri']=$uri;rejected(fn()=>PosterRasterService::read($bad,7,microtime(true)+4));}
    unlink($fixtureDir.'/'.$builtin['uri']);symlink($fixtureDir.'/outside.png',$fixtureDir.'/'.$builtin['uri']);rejected(fn()=>PosterRasterService::read($builtin,7,microtime(true)+4));unlink($fixtureDir.'/'.$builtin['uri']);
    rmdir($fixtureDir.'/resource/distribution-posters');rmdir($fixtureDir.'/resource');
    unlink($fixtureDir.'/uploads/escape.png');unlink($fixtureDir.'/uploads/fixture.png');unlink($fixtureDir.'/outside.png');rmdir($fixtureDir.'/uploads');rmdir($fixtureDir);
    echo "PASS poster asset boundaries: $checks checks (tenant/qualification/publish/version/receipts/private COS/bounds/raster/local paths); no external I/O\n";
}
