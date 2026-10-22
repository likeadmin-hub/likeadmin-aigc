<?php
$root=getenv('AIGC_SERVER_ROOT')?:dirname(__DIR__);
require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
use think\facade\{Db,Env};
use app\common\service\virtualpay\{VirtualPayConfigService as Config,VirtualPayKeySetup as Setup};
$settings=Db::getConfig();$default=$settings['default'];$connection=$settings['connections'][$default];
if(($connection['hostname']??'')!=='127.0.0.1'||($connection['database']??'')!=='z_cn')throw new RuntimeException('Local z_cn host guard required; test writes only isolated temporary tables');
$admin=Db::connect();$database='vpay_vault_fixture_'.bin2hex(random_bytes(6));$oldKey=(string)env('virtualpay.credential_key','');$directory=sys_get_temp_dir().'/'.$database;mkdir($directory,0700);
$tables=[];
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
    $config=$settings;$config['connections']['vault_fixture']=$connection;$config['connections']['vault_fixture']['prefix']=$database.'_';$config['default']='vault_fixture';\think\facade\Config::set($config,'database');
    $sql=file_get_contents($root.'/public/install/db/like.sql');
    foreach(['tenant_config','wechat_authorizers','wechat_open_platform','virtual_pay_config','virtual_pay_config_version','virtual_pay_product','virtual_pay_session'] as $table){
        if(!preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `la_'.preg_quote($table,'/').'`\s*\(.*?;\s*/s',$sql,$match))throw new RuntimeException('Missing authoritative install schema '.$table);
        $name=$database.'_'.$table;$tables[]=$name;Db::execute(str_replace('`la_'.$table.'`','`'.$name.'`',$match[0]));
    }
    request()->withServer(['HTTP_HOST'=>'vault.fixture.invalid','SERVER_PORT'=>80]);
    $tenant=891;foreach(['app_id'=>'wx-vault-fixture','app_secret'=>'fixture-manual-secret'] as $name=>$value)Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'mnp_setting','name'=>$name,'value'=>$value]);
    Env::set(['virtualpay'=>['credential_key'=>'']]);
    check(Config::detail($tenant,0)['vault_ready']===false,'first-time setup reports missing vault before save');
    try{Config::save($tenant,0,['enabled_platforms'=>['ios'],'offer_id'=>'123','app_key'=>'fixture-appkey-012345']);throw new LogicException('save without vault accepted');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'加密密钥'),'exact missing key failure reproduced');}
    check(Db::name('virtual_pay_config_version')->count()===0&&Db::name('virtual_pay_config')->count()===0,'failed save rolls back all rows');
    $envFile=$directory.'/.env';file_put_contents($envFile,"[DATABASE]\nDATABASE = fixture\n");$init=Setup::initialize($envFile,'',[Setup::class,'hasEncryptedRecords']);
    $key=Setup::readKey(parse_ini_file($envFile,true,INI_SCANNER_RAW));Env::set(['virtualpay'=>['credential_key'=>$key]]);
    $saved=Config::save($tenant,0,['enabled_platforms'=>['ios','android'],'offer_id'=>'123','app_key'=>'fixture-appkey-012345']);
    check($saved['vault_ready']&&$saved['enabled']===1&&$saved['key_configured'],'generated key permits actual config save');
    check(!isset($saved['app_key'])&&!str_contains(json_encode($saved),'fixture-appkey-012345'),'AppKey never returned');
    $version=Config::active($tenant,0);check(Config::secret($version,'app_key')==='fixture-appkey-012345','stored key decrypts with independent installation key');
    Config::save($tenant,0,['enabled_platforms'=>['ios'],'offer_id'=>'123','app_key'=>'']);
    check(Config::secret(Config::active($tenant,0),'app_key')==='fixture-appkey-012345','blank AppKey preserves credential');
    Env::set(['virtualpay'=>['credential_key'=>str_repeat('x',64)]]);$bad=Config::detail($tenant,0);
    check($bad['vault_ready']===false&&$bad['token']===''&&$bad['aes_key']==='','wrong key produces no partial credential echo');
    Db::name('wechat_authorizers')->insert(['tenant_id'=>$tenant,'authorizer_appid'=>'wx-vault-fixture','authorizer_type'=>'miniprogram','authorization_status'=>1]);
    check(Config::detail($tenant,0)['vault_ready']===false,'authorized mode also detects unavailable AppKey decryption');
    Env::set(['virtualpay'=>['credential_key'=>'']]);$closed=Config::save($tenant,0,['enabled_platforms'=>[],'version_id'=>Db::name('virtual_pay_config')->value('version_id')]);
    check($closed['enabled']===0,'missing vault still permits disabling payment');
    $history=$directory.'/history.env';file_put_contents($history,"APP_DEBUG = false\n");
    try{Setup::initialize($history,'',[Setup::class,'hasEncryptedRecords']);throw new LogicException('history regenerated');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'恢复原密钥'),'real historical credentials prevent regeneration');}
    check(Setup::hasEncryptedRecords(),'CLI guard sees real encrypted configuration history');
    Db::name('virtual_pay_config_version')->where('tenant_id',$tenant)->delete();
    Db::name('virtual_pay_session')->insert(['tenant_id'=>$tenant,'user_id'=>1,'app_id'=>'wx-vault-fixture','session_id'=>hash('sha256','fixture-session'),'openid'=>'fixture-openid','session_key'=>$version['app_key'],'expires_at'=>time()+60]);
    check(Setup::hasEncryptedRecords(),'CLI guard also sees encrypted sessions without configuration history');
    echo "PASS isolated virtual payment vault/save/disable/history: $checks checks; no WeChat requests\n";
}finally{
    Env::set(['virtualpay'=>['credential_key'=>$oldKey]]);\think\facade\Config::set($settings,'database');foreach(array_reverse($tables) as $table){if(!preg_match('/^vpay_vault_fixture_[a-f0-9]{12}_[a-z_]+$/D',$table))throw new LogicException('Unsafe fixture table name');$admin->execute('DROP TABLE IF EXISTS `'.$table.'`');}
    foreach(array_merge(glob($directory.'/*')?:[],glob($directory.'/.*')?:[]) as $file)if(is_file($file))unlink($file);rmdir($directory);
}
