<?php
declare(strict_types=1);
namespace app\common\model\wechat {
    class FakeRow implements \ArrayAccess {
        public function __construct(public array $data=[]){ }
        public function isEmpty(){return !$this->data;}
        public function toArray(){return $this->data;}
        public function save($data){$this->data=array_replace($this->data,$data);WechatCredential::$rows[$this->data['tenant_id']]=$this->data;}
        public function offsetExists($o):bool{return isset($this->data[$o]);}
        public function offsetGet($o):mixed{return $this->data[$o]??null;}
        public function offsetSet($o,$v):void{$this->data[$o]=$v;}
        public function offsetUnset($o):void{unset($this->data[$o]);}
    }
    class FakeQuery {
        private $tenant=0;
        public function __construct(private bool $authorizers=false){}
        public function where($key,$value=null){$this->tenant=is_array($key)?(int)($key['tenant_id']??0):($key==='tenant_id'?(int)$value:$this->tenant);return $this;}
        public function findOrEmpty(){return new FakeRow($this->authorizers?[]:(WechatCredential::$rows[$this->tenant]??[]));}
        public function value($field){return $this->findOrEmpty()[$field];}
    }
    class WechatCredential {
        public static array $rows=[];
        public static function withoutGlobalScope(){return new FakeQuery;}
        public static function create($data){self::$rows[$data['tenant_id']]=$data;return new FakeRow($data);}
    }
    class WechatAuthorizer {public static function withoutGlobalScope(){return new FakeQuery(true);}}
}
namespace app\common\service\wechat {
    class WechatCredentialService {public static function encrypt($v){return 'encrypted:'.$v;}public static function decrypt($v){return $v;}public static function mask($v){return '******';}}
    class MiniProgramDomainService {public static function host($id){return 'tenant'.$id.'.example.com';}}
}
namespace app\common\logic {class BaseLogic {}}
namespace app\common\service {
    class ConfigService {
        public static array $values=[];
        public static function get($type,$key='',$default=null){return self::$values[\request()->tenantId][$type][$key]??$default;}
        public static function set($type,$key,$value){self::$values[\request()->tenantId][$type][$key]=$value;}
    }
    class FileService {public static function getFileUrl($v){return $v;}}
}
namespace {
    $context=new class {public $tenantId=1;public function host($v=false){return 'tenant'.$this->tenantId.'.example.com';}};
    function request(){global $context;return $context;}
    function url(...$args){return 'https://tenant1.example.com/callback';}
    $root=getenv('WECHAT_TEST_ROOT')?:dirname(__DIR__,2);
    foreach(['app/common/service/wechat/WechatDomainSettings.php','app/common/service/wechat/MiniProgramPopupService.php','app/common/service/wechat/OpenPlatformService.php','app/tenantapi/logic/channel/MnpSettingsLogic.php','app/tenantapi/logic/channel/OfficialAccountSettingLogic.php'] as $file)require $root.'/'.$file;
    use app\common\service\wechat\OpenPlatformService as Service;
    use app\common\model\wechat\WechatCredential as Credential;
    function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
    Credential::$rows=[1=>['tenant_id'=>1,'app_secret'=>'existing-secret','settings_json'=>json_encode(['service_path'=>'/pages/service','download_file_domain'=>'https://old.example.com','qr_popup'=>['title'=>'Keep popup']])],2=>['tenant_id'=>2,'settings_json'=>'{"download_file_domain":"https://foreign.example.com"}']];
    $foreign=Credential::$rows[2];Service::saveCredentials(1,['settings_json'=>['download_file_domain'=>"https://Cloud.Example.com/\nhttps://old.example.com"],'app_secret'=>'******']);
    $settings=json_decode(Credential::$rows[1]['settings_json'],true);
    check($settings['download_file_domain']==="https://cloud.example.com\nhttps://old.example.com",'partial domain save persists normalized multi-domain input');
    check($settings['service_path']==='/pages/service'&&$settings['qr_popup']['title']==='Keep popup'&&Credential::$rows[1]['app_secret']==='existing-secret','domain-only save preserves popup, service path and account credentials');
    check(Credential::$rows[2]===$foreign,'other tenant configuration is unchanged');
    $config=(new app\tenantapi\logic\channel\MnpSettingsLogic)->getConfig();
    check(str_contains($config['download_file_domain'],'https://cloud.example.com')&&str_contains($config['download_file_domain'],'https://tenant1.example.com'),'reopening legacy configuration retains saved domains and required API origin');
    $context->tenantId=2;$config=(new app\tenantapi\logic\channel\MnpSettingsLogic)->getConfig();check(!str_contains($config['download_file_domain'],'cloud.example.com')&&str_contains($config['download_file_domain'],'foreign.example.com'),'domain reads are tenant scoped');
    $context->tenantId=1;$logic=new app\tenantapi\logic\channel\OfficialAccountSettingLogic;$logic->saveDomains(['business_domain'=>"https://h5.example.com\ncdn.example.com",'web_auth_domain'=>'login.example.com']);$config=$logic->getConfig();check($config['business_domain']==="h5.example.com\ncdn.example.com"&&$config['web_auth_domain']==='login.example.com','official domains persist and reload without requiring manual AppSecret');
    Service::saveCredentials(1,['settings_json'=>['business_domain'=>'tenant1.example.com','_managed_business_domain'=>true]]);
    check(!json_decode(Credential::$rows[1]['settings_json'],true)['_managed_business_domain'],'unchanged legacy business suggestion and client flag do not trigger new business-domain registration');
    Service::saveCredentials(1,['settings_json'=>['business_domain'=>'h5.example.com']]);
    check(json_decode(Credential::$rows[1]['settings_json'],true)['_managed_business_domain'],'an edited business domain is marked for WeChat synchronization');
    $before=Credential::$rows;try{Service::saveCredentials(1,['settings_json'=>['download_file_domain'=>'https://bad.example.com/path']]);}catch(InvalidArgumentException $e){}check(Credential::$rows===$before,'invalid domain leaves saved settings untouched');
    echo "All domain persistence checks passed\n";
}
