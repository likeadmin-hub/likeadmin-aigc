<?php
$root=getenv('AIGC_SERVER_ROOT')?:dirname(__DIR__);
require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
use think\facade\{Db,Env};
use app\common\service\virtualpay\{VirtualPayConfigService as Config,VirtualPayVault as Vault,VirtualPaySessionService as Sessions,VirtualPayGateway as Gateway};
$settings=Db::getConfig();$connection=$settings['connections'][$settings['default']];
if(($connection['hostname']??'')!=='127.0.0.1'||($connection['database']??'')!=='z_cn')throw new RuntimeException('Local fixture guard required');
$admin=Db::connect();$prefix='vpay_vault_fixture_'.bin2hex(random_bytes(6)).'_';$tables=[];
$oldKey=(string)env('virtualpay.credential_key','');$external=getenv('PHP_VIRTUALPAY_CREDENTIAL_KEY');$envHash=hash_file('sha256',$root.'/.env');
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,string $message){try{$fn();}catch(RuntimeException $error){check(true,$message);return;}throw new RuntimeException($message);}
try{
    $config=$settings;$config['connections']['vault_fixture']=$connection;$config['connections']['vault_fixture']['prefix']=$prefix;$config['default']='vault_fixture';\think\facade\Config::set($config,'database');
    $sql=file_get_contents($root.'/public/install/db/like.sql');
    foreach(['tenant','tenant_config','wechat_authorizers','wechat_open_platform','virtual_pay_config','virtual_pay_config_version','virtual_pay_product','virtual_pay_session','user_auth'] as $table){
        if(!preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `la_'.preg_quote($table,'/').'`\s*\(.*?;\s*/s',$sql,$match))throw new RuntimeException('Missing install schema '.$table);
        $name=$prefix.$table;$tables[]=$name;Db::execute(str_replace('`la_'.$table.'`','`'.$name.'`',$match[0]));
    }
    request()->withServer(['HTTP_HOST'=>'vault.fixture.invalid','SERVER_PORT'=>80]);request()->source=app\common\enum\AdminTerminalEnum::PLATFORM;
    putenv('PHP_VIRTUALPAY_CREDENTIAL_KEY');Env::set(['virtualpay'=>['credential_key'=>'']]);
    foreach([891,892,893] as $tenant)foreach(['app_id'=>'wx-fixture-'.$tenant,'app_secret'=>'fixture-secret'] as $name=>$value)Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'mnp_setting','name'=>$name,'value'=>$value]);
    foreach([891,892,893] as $fixtureTenant)Db::name('tenant')->insert(['id'=>$fixtureTenant,'sn'=>'vault-fixture-'.$fixtureTenant,'name'=>'Vault fixture','tactics'=>0,'create_time'=>time()]);
    $tenant=891;$app='wx-fixture-'.$tenant;
    $detail=Config::detail($tenant,0);check($detail['vault_generated']===false,'new tenant has no key before generation');
    check(Db::name('tenant_config')->where('type','virtual_pay_vault')->count()===0,'GET detail does not create keys');
    // Two independent PHP processes must converge on the same first tenant key.
    $processes=[];$snapshot=dirname(__DIR__);
    for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/virtualpay-vault-concurrent-child.php',$root,$snapshot,$prefix,(string)$tenant],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start fixture child');fclose($pipes[0]);$processes[]=[$process,$pipes];}
    foreach($processes as [$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0&&$stdout==='generated','parallel initialization failed: '.$stderr);}
    $scope=['tenant_id'=>$tenant,'type'=>'virtual_pay_vault','name'=>'credential_key'];$row=Db::name('tenant_config')->where($scope)->find();
    check(Db::name('tenant_config')->where($scope)->count()===1,'concurrent initialization writes one tenant key');
    $data=Config::generateVault($tenant,0,['app_id'=>$app,'tenant_id'=>892]);
    check($data['vault_generated']&&$data['vault_ready']&&!isset($data['credential_key']),'generation returns status only');
    check(Db::name('tenant_config')->where($scope)->value('value')===$row['value'],'repeated generation preserves existing tenant key');
    rejects(fn()=>Config::generateVault(892,0,['app_id'=>$app]),'stale or foreign AppID rejected');
    check(Db::name('tenant_config')->where(['tenant_id'=>892,'type'=>'virtual_pay_vault'])->count()===0,'client tenant field cannot generate another key');
    $cipher=Vault::encrypt('private-fixture',$tenant);check(str_starts_with($cipher,'vp2:')&&Vault::decrypt($cipher,$tenant)==='private-fixture','tenant ciphertext roundtrip');
    $saved=Config::save(892,0,['enabled_platforms'=>['ios','android'],'offer_id'=>'123','app_key'=>'fixture-appkey-012345']);
    check($saved['vault_generated']&&$saved['vault_ready']&&$saved['enabled']===1,'first save auto generates without environment configuration');
    check(!isset($saved['app_key'])&&!str_contains(json_encode($saved),'fixture-appkey-012345'),'AppKey not echoed');
    $other=Db::name('tenant_config')->where(['tenant_id'=>892,'type'=>'virtual_pay_vault'])->value('value');check($other!==$row['value'],'two tenants store independent encrypted keys');
    check(Vault::decrypt($cipher,892)==='','cross tenant ciphertext cannot decrypt');
    $version=Config::active(892,0);request()->tenantId=891;
    check(Config::secret($version,'app_key')==='fixture-appkey-012345','worker uses version tenant instead of request tenant');
    check(Config::secret($version,'mini_secret')==='fixture-secret','manual gateway secret uses tenant encryption');
    $signing=[];$gateway=new Gateway(static function($method,$path,$query,$body)use(&$signing){$signing=[$path,$query,$body];return ['errcode'=>0];});
    $gateway->request($version,'/xpay/query_order',['openid'=>'fixture-openid','env'=>0,'order_id'=>'fixture-order']);
    check($signing[1]['pay_sig']===hash_hmac('sha256','/xpay/query_order&'.$signing[2],'fixture-appkey-012345'),'gateway signs exact request bytes with decrypted tenant AppKey');
    Config::save(892,0,['enabled_platforms'=>['ios'],'offer_id'=>'123','app_key'=>'']);
    check(Config::secret(Config::active(892,0),'app_key')==='fixture-appkey-012345','blank AppKey keeps old value');
    check(Db::name('tenant_config')->where(['tenant_id'=>892,'type'=>'virtual_pay_vault'])->value('value')===$other,'save never rotates tenant key');
    Db::name('user_auth')->insert(['tenant_id'=>892,'user_id'=>87,'terminal'=>1,'openid'=>'fixture-openid','create_time'=>time()]);
    request()->tenantId=892;
    $sessionService=new Sessions(new Gateway(static fn()=>['openid'=>'fixture-openid','session_key'=>'fixture-session-secret']));
    $session=$sessionService->exchange(892,87,'fixture-code');$sessionRow=Db::name('virtual_pay_session')->where('tenant_id',892)->find();
    check(str_starts_with($sessionRow['session_key'],'vp2:')&&$sessionService->get(892,87,'wx-fixture-892',$session)['session_key']==='fixture-session-secret','session submit/read use same tenant key');
    rejects(fn()=>$sessionService->get(891,87,'wx-fixture-892',$session),'session cannot cross tenants');
    // Historical global ciphertext remains readable, including after a tenant key is generated.
    Env::set(['virtualpay'=>['credential_key'=>str_repeat('h',64)]]);$legacy=Vault::encrypt('legacy-fixture-secret');
    check(Vault::decrypt($legacy,893)==='legacy-fixture-secret','legacy ciphertext reads original installation key');
    Config::generateVault(893,0);check(Vault::decrypt($legacy,893)==='legacy-fixture-secret','generation does not replace historical decryption key');
    $cfgId=Db::name('virtual_pay_config')->insertGetId(['tenant_id'=>893,'app_id'=>'wx-fixture-893','env'=>0]);
    $oldFields=['mini_secret'=>'fixture-secret','app_key'=>'legacy-fixture-appkey','token'=>'fixture-token','aes_key'=>str_repeat('A',43)];$sealed=[];foreach($oldFields as $field=>$value)$sealed[$field]=Vault::encrypt($value);
    $legacyId=Db::name('virtual_pay_config_version')->insertGetId(['config_id'=>$cfgId,'tenant_id'=>893,'app_id'=>'wx-fixture-893','env'=>0,'offer_id'=>'123','created_at'=>time()]+$sealed);
    Db::name('virtual_pay_config')->where('id',$cfgId)->update(['version_id'=>$legacyId]);
    Config::save(893,0,['enabled_platforms'=>['ios'],'offer_id'=>'123','app_key'=>'']);$updated=Config::active(893,0);
    check(str_starts_with($updated['app_key'],'vp2:')&&Config::secret($updated,'app_key')==='legacy-fixture-appkey','save upgrades copied credentials to tenant encryption');
    check(Db::name('virtual_pay_config_version')->where('id',$legacyId)->value('app_key')===$sealed['app_key'],'old order configuration version remains immutable');
    Db::name('virtual_pay_session')->insert(['tenant_id'=>893,'user_id'=>87,'app_id'=>'wx-fixture-893','session_id'=>hash('sha256','legacy-session'),'openid'=>'fixture-openid','session_key'=>$legacy,'expires_at'=>time()+60]);
    check($sessionService->get(893,87,'wx-fixture-893','legacy-session')['session_key']==='legacy-fixture-secret','old login sessions remain readable');
    Env::set(['virtualpay'=>['credential_key'=>'']]);check(Config::secret($version,'app_key')==='fixture-appkey-012345','new credentials need no global key');
    rejects(fn()=>Config::secret(Config::version(893,$legacyId),'app_key'),'missing legacy key fails explicitly without overwriting history');
    // Corrupt or transplanted key wrappers must not trigger replacement.
    Db::name('tenant_config')->where($scope)->update(['value'=>$other]);
    rejects(fn()=>Vault::generate(891),'tenant-bound key envelope cannot be transplanted');
    check(Db::name('tenant_config')->where($scope)->value('value')===$other,'invalid existing key never replaced');
    check(Vault::decrypt($cipher,891)==='','invalid tenant key does not silently fall back');
    check(hash_file('sha256',$root.'/.env')===$envHash,'generation does not write environment files');
    echo "PASS tenant vault isolation/generation/concurrency/legacy/session: $checks checks; no WeChat calls\n";
}finally{
    Env::set(['virtualpay'=>['credential_key'=>$oldKey]]);putenv($external===false?'PHP_VIRTUALPAY_CREDENTIAL_KEY':'PHP_VIRTUALPAY_CREDENTIAL_KEY='.$external);\think\facade\Config::set($settings,'database');
    foreach(array_reverse($tables) as $table){if(!preg_match('/^vpay_vault_fixture_[a-f0-9]{12}_[a-z_]+$/D',$table))throw new LogicException('Unsafe fixture table');$admin->execute('DROP TABLE IF EXISTS `'.$table.'`');}
}
