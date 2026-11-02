<?php
declare(strict_types=1);
// In-memory ownership and tenant configuration only. No database or WeChat API calls.
namespace PosterH5Fixture {
    class Record extends \ArrayObject { public function isEmpty():bool{return !$this->count();} }
    class Query {
        private array $where=[];
        public function __construct(private string $table){}
        public function where($key,$value=null):self{$this->where+=is_array($key)?$key:[$key=>$value];return $this;}
        public function whereNull($key):self{$this->where[$key]=null;return $this;}
        public function lock(...$args):self{return $this;}
        public function find():?array{foreach(Data::$rows[$this->table]??[] as $row){foreach($this->where as $k=>$v)if(($row[$k]??null)!=$v)continue 2;return $row;}return null;}
        public function findOrEmpty():Record{return new Record($this->find()??[]);}
    }
    class Data {public static array $rows=[];public static string $root='platform.example';}
}
namespace think\facade {
    class Db {public static function name($table):\PosterH5Fixture\Query{return new \PosterH5Fixture\Query($table);}}
    class Config {public static function get($name){return \PosterH5Fixture\Data::$root;}}
}
namespace app\common\model\file {class TenantFile {public static function where($where){return (new \PosterH5Fixture\Query('tenant_file'))->where($where);}}}
namespace app\common\model\user {class User {public static function where($where){return (new \PosterH5Fixture\Query('user'))->where($where);}}}
namespace app\common\service {class FileService {public static function getFileUrlByStorage(...$args):string{return '/background.png';}}}
namespace app\common\service\tenant {
    class TenantDomainAliasService {public static function getPrimaryAlias($tenant):string{return $tenant['domain_alias']??'';}}
}
namespace app\common\service\distribution {
    class DistributionService {
        public static bool $enabled=true,$frozen=false;
        public static function assertEnabled($id):void{if(!self::$enabled)throw new \RuntimeException('分销功能未开启');}
        public static function ensurePromoter($tenantId,$userId):array{return ['is_frozen'=>self::$frozen,'invite_code'=>'OWN7'];}
    }
}
namespace app\common\service\wechat {
    class WeChatMnpService {public static int $calls=0;public function __construct(...$args){self::$calls++;throw new \RuntimeException('微信小程序配置缺失');}}
}
namespace {
    use PosterH5Fixture\Data;
    use app\common\service\distribution\{PosterService,DistributionService};
    use app\common\service\wechat\WeChatMnpService;
    function request(){return new class {public function domain(){return 'https://attacker.invalid';}};}
    require dirname(__DIR__).'/app/common/service/tenant/TenantUrlService.php';
    require dirname(__DIR__).'/app/common/service/distribution/PosterService.php';
    $service=dirname(__DIR__).'/app/common/service/distribution/PosterH5CodeService.php';
    if(is_file($service))require $service;
    function checkH5($ok,$message):void{if(!$ok)throw new \RuntimeException($message);}
    function rejectH5(callable $fn,string $message):void{try{$fn();}catch(\RuntimeException $e){checkH5(str_contains($e->getMessage(),$message),'Unexpected error: '.$e->getMessage());return;}throw new \RuntimeException('Expected rejection: '.$message);}
    $_SERVER['HTTPS']='on';
    Data::$rows=[
        'tenant'=>[['id'=>7,'sn'=>'tenant7','access_mode'=>'id','delete_time'=>null]],
        'distribution_poster_template'=>[['id'=>2,'tenant_id'=>7,'delete_time'=>0,'status'=>1,'published_version'=>11]],
        'distribution_poster_version'=>[['id'=>11,'tenant_id'=>7,'template_id'=>2,'name'=>'Fixture','layout_json'=>'{"canvas":{"background_asset_id":5}}']],
        'tenant_file'=>[['id'=>5,'tenant_id'=>7,'type'=>10,'source'=>0,'uri'=>'uploads/bg.png']],
        'user'=>[['id'=>9,'tenant_id'=>7,'nickname'=>'推广员','avatar'=>'','is_disable'=>0]],
    ];
    $input=['id'=>2,'channel'=>'h5','url'=>'https://attacker.invalid','host'=>'attacker.invalid','invite_code'=>'OTHER'];
    $h5=PosterService::code(7,9,$input);
    checkH5($h5['channel']==='h5'&&$h5['destination']==='https://platform.example/mobile/?tenant_id=7&invite_code=OWN7','H5 must use trusted tenant origin and own invite');
    checkH5(WeChatMnpService::$calls===0,'H5 required WeChat configuration');
    $oldKey=$h5['cache_key'];
    Data::$rows['tenant'][0]['access_mode']='subdomain';
    $subdomain=PosterService::code(7,9,$input);
    checkH5($subdomain['destination']==='https://tenant7.platform.example/mobile/?tenant_id=7&invite_code=OWN7'&&$subdomain['cache_key']!==$oldKey,'Destination change reused cached QR');
    Data::$rows['tenant'][0]+=['domain_alias'=>'brand.example','domain_alias_enable'=>0];
    Data::$rows['tenant'][0]['access_mode']='alias';
    $alias=PosterService::code(7,9,$input);
    checkH5($alias['destination']==='https://brand.example/mobile/?tenant_id=7&invite_code=OWN7','Saved tenant alias not used');
    Data::$root='';
    checkH5(PosterService::code(7,9,$input)['destination']===$alias['destination'],'Owned alias should not require platform domain');
    Data::$rows['tenant'][0]['access_mode']='id';
    rejectH5(fn()=>PosterService::code(7,9,$input),'域名');
    Data::$root='platform.example';
    rejectH5(fn()=>PosterService::code(7,9,['id'=>2]),'微信小程序');
    checkH5(WeChatMnpService::$calls===1,'Default mobile channel changed');
    rejectH5(fn()=>PosterService::code(7,9,['id'=>2,'channel'=>'unknown']),'渠道');
    DistributionService::$frozen=true;rejectH5(fn()=>PosterService::code(7,9,$input),'推广资格已停用');DistributionService::$frozen=false;
    DistributionService::$enabled=false;rejectH5(fn()=>PosterService::code(7,9,$input),'分销功能未开启');DistributionService::$enabled=true;
    rejectH5(fn()=>PosterService::code(8,9,$input),'海报不存在');
    Data::$rows['distribution_poster_template'][0]['status']=0;rejectH5(fn()=>PosterService::code(7,9,$input),'海报已下架');
    echo "PASS H5 tenant ID/subdomain/alias links, invite ownership, hostile URL/Host isolation, channel cache isolation, no WeChat dependency and unchanged eligibility guards\n";
}
