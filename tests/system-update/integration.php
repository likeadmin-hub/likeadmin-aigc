<?php
// Local DB fixtures are rolled back. No installed files, credentials or upstream orders are changed.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\license\SignedLicenseProtocol as P;
use app\common\service\update\UpdateLicenseService as L;
use app\common\service\update\UpdateSourceClient as Client;
use app\common\service\update\SystemUpdateProtocol as Protocol;
use app\common\service\update\SystemPackageGrantService as Grants;
use app\common\service\update\SystemPackageUpdateService as Packages;
use app\common\service\update\UpdateProtocolException;

class FixtureSystemClient extends Client {
    public $key; public string $hash; public string $version; public int $calls=0; public string $error='';
    protected function sendSystem(string $endpoint,array $params,array $source): string {
        $this->calls++;
        if($params['domain']!=='platform.fixture') throw new RuntimeException('Tenant request host used for system authorization');
        $certificate=P::decode($params['license'])->payload;
        $now=time();
        if($this->error!=='') $data=['error_code'=>$this->error,'license_no'=>$certificate->license_no,'license_version'=>$certificate->version,
            'request_nonce'=>$params['nonce'],'issued_at'=>$now,'domain'=>$params['domain'],'machine_fingerprint_hash'=>$params['machine_fingerprint_hash']];
        elseif($endpoint==='system/versions') $data=['lists'=>[['id'=>100,'version'=>$this->version,'can_download'=>true,'deny_reason'=>'','deny_message'=>'']]];
        else $data=['product_code'=>Client::PRODUCT_CODE,'version'=>$this->version,'target_version'=>$this->version,'sha256'=>$this->hash,'format'=>'zip',
            'download_url'=>'https://fixture.invalid/aigc/v1/system/download?token=private-fixture',
            'site_grant'=>['license_no'=>$certificate->license_no,'license_version'=>$certificate->version,'domains'=>$certificate->domains,
                'machine_fingerprint_hash'=>$certificate->machine_fingerprint_hash,'version_id'=>100,'issued_at'=>$now,'expires_at'=>$now+3600]];
        $o=(object)['code'=>$this->error===''?1:0,'msg'=>'fixture','data'=>$data,'request_id'=>'fixture','server_time'=>$now,'object'=>(object)[]];
        openssl_sign(P::json($o),$sig,$this->key,OPENSSL_ALGO_SHA256);$o->signature=base64_encode($sig);return P::json($o);
    }
}
class SystemSiteFixture extends \app\common\service\license\SiteLicenseAccessService {
    public function row(): array {return $this->ensureRow($this->context());}
}
$n=0;$assert=static function($ok,$name)use(&$n){if(!$ok)throw new RuntimeException($name);$n++;};
$reject=static function($fn,$name,$code='')use($assert){try{$fn();}catch(Throwable $e){$assert($code===''||($e instanceof UpdateProtocolException&&$e->errorCode===$code),$name.': '.$e->getMessage());return;}throw new RuntimeException('Accepted '.$name);};
Client::getSource();
$path=tempnam(sys_get_temp_dir(),'system-grant-');file_put_contents($path,'fixture archive');
Db::startTrans();
try {
    $key=openssl_pkey_new(['private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
    Db::name('update_source')->where('status',1)->update(['status'=>0]);
    Db::name('update_source')->insert(['name'=>'rollback system fixture','base_url'=>'http://fixture.invalid','license_key'=>'fixture',
        'online_base_url'=>'http://fixture.invalid','online_license_key'=>'fixture','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
    request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'platform.fixture']));
    $license=new L();$machine=$license->machineCode();
    $payload=(object)['schema_version'=>3,'license_no'=>'LIC-system-fixture','version'=>3,'product_code'=>Client::PRODUCT_CODE,'domains'=>[$machine['domain']],
        'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,
        'update_mode'=>'annual','update_until'=>time()-10,'update_rights_revision'=>4,'issuer'=>(object)['copyright'=>[]]];
    $cert=static function($p)use($key){openssl_sign(P::json($p),$sig,$key,OPENSSL_ALGO_SHA256);return P::json((object)['payload'=>$p,'signature'=>base64_encode($sig),'algorithm'=>'RSA-SHA256']);};
    $license->storeCertificate($cert($payload));
    $assert(empty($license->info()['payload']['max_core_version']),'certificate revision displayed as core version');
    request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'tenant.fixture']));
    $assert($license->requestContext()['domain']==='platform.fixture','generic update context uses tenant Host');
    $client=new FixtureSystemClient();$client->key=$key;$client->hash=hash_file('sha256',$path);$client->version='1.0.386';
    $assert($client->systemRequest('system/versions')['data']['lists'][0]['can_download'],'expired annual service denied an acquired version');
    $package=['package_id'=>'fixture','type'=>'system','source'=>'cloud','app_code'=>'','version'=>$client->version,'format'=>'zip','local_path'=>$path,
        'sha256'=>$client->hash,'package_size'=>filesize($path),'manifest_json'=>'{}','status'=>'preflight_success','create_time'=>time(),'update_time'=>time()];
    $package['id']=Db::name('update_package')->insertGetId($package);
    $response=$client->systemRequest('system/package');$grants=new Grants($client);$grants->save($package['id'],$response,$package);
    $assert($grants->ensure($package)['version']===$client->version,'stored grant validation');
    $assert($client->calls===2,'local valid grant unnecessarily calls upstream');
    $saved=Db::name('system_package_grant')->where('package_id',$package['id'])->find();
    $assert($saved['response_json']===$response['response_json'],'signed response changed on persistence');
    $assert(!isset(Db::name('update_package')->where('id',$package['id'])->find()['response_json']),'private proof exposed as public package');
    $expired=P::decode($response['response_json']);$expired->data->site_grant->issued_at=time()-3600;$expired->data->site_grant->expires_at=time();$expired->server_time=time()-3600;
    unset($expired->signature);openssl_sign(P::json($expired),$sig,$key,OPENSSL_ALGO_SHA256);$expired->signature=base64_encode($sig);
    Db::name('system_package_grant')->where('package_id',$package['id'])->update(['response_json'=>P::json($expired)]);
    $assert($grants->ensure($package)['site_grant']['expires_at']>time(),'expired grant not reauthorized online');
    $assert($client->calls===3,'expired grant renewed without online request');
    $foreign=P::decode($response['response_json']);$foreign->data->site_grant->domains=['other.fixture'];unset($foreign->signature);openssl_sign(P::json($foreign),$sig,$key,OPENSSL_ALGO_SHA256);$foreign->signature=base64_encode($sig);
    Db::name('system_package_grant')->where('package_id',$package['id'])->update(['response_json'=>P::json($foreign)]);
    $reject(fn()=>$grants->ensure($package),'foreign signed proof','SYSTEM_PACKAGE_GRANT_INVALID');
    $assert($client->calls===3,'foreign proof silently replaced');
    $grants->save($package['id'],$response,$package);
    file_put_contents($path,'tampered archive');
    $reject(fn()=>(new Packages())->apply($package['id']),'preflight_success bypassed final checksum','SYSTEM_PACKAGE_HASH_MISMATCH');
    file_put_contents($path,'fixture archive');
    $payload->version=4;$license->storeCertificate($cert($payload),true);
    $assert($grants->ensure($package)['site_grant']['license_version']===4,'renewal reused old-version grant');
    $cache=(new SystemSiteFixture())->row();$ctx=$license->verifiedSiteContext();$nonce='trusted-revocation-fixture';
    $denial=(object)['code'=>0,'msg'=>'revoked','request_id'=>'fixture','server_time'=>time(),'data'=>[
        'license_no'=>$ctx['license_no'],'license_version'=>$ctx['license_version'],'domain'=>$ctx['domain'],
        'machine_fingerprint_hash'=>$ctx['machine_fingerprint_hash'],'request_nonce'=>$nonce,'issued_at'=>time(),'error_code'=>'LICENSE_INVALID']];
    openssl_sign(P::json($denial),$sig,$key,OPENSSL_ALGO_SHA256);$denial->signature=base64_encode($sig);
    Db::name('site_license_access_cache')->where('id',$cache['id'])->update(['status'=>'blocked','response_json'=>P::json($denial),'request_nonce'=>$nonce,'error_code'=>'LICENSE_INVALID']);
    $reject(fn()=>$grants->ensure($package),'valid package grant overrode known revocation','LICENSE_INVALID');
    Db::name('site_license_access_cache')->where('id',$cache['id'])->update(['status'=>'pending']);
    Db::name('system_package_grant')->where('package_id',$package['id'])->delete();
    $client->error='FREE_UPDATE_DISABLED';
    $reject(fn()=>$grants->ensure($package),'missing grant reused free-disabled package','FREE_UPDATE_DISABLED');
    $assert(!Db::name('system_package_grant')->where('package_id',$package['id'])->find(),'signed refusal saved as allow');
    $client->error='UPDATE_VERSION_NOT_GRANTED';
    $reject(fn()=>$client->systemRequest('system/package'),'structured version refusal','UPDATE_VERSION_NOT_GRANTED');
    $r=new ReflectionClass(Packages::class);$select=$r->getMethod('selectNextVersion');$select->setAccessible(true);
    $rows=[['version'=>'1.0.387','can_download'=>false],['version'=>'1.0.386','can_download'=>true]];
    $assert($select->invoke(new Packages(),$rows,'1.0.385')['version']==='1.0.386','automatic target selected denied version');
    $assert($select->invoke(new Packages(),[['version'=>'1.0.116','can_download'=>false]],'1.0.100')===[],'denied bridge selected');
    $assert($select->invoke(new Packages(),[['version'=>'1.0.386']],'1.0.385')===[],'missing version permission grants automatic update');
    $payload->version=5;$payload->license_type='free';$payload->is_perpetual=false;$payload->expires_at=time()+365*86400;
    $payload->update_mode='tenant_policy';$license->storeCertificate($cert($payload),true);
    $cache=(new SystemSiteFixture())->row();$ctx=$license->verifiedSiteContext();$nonce='free-disabled-fixture';$now=time();
    $disabled=(object)['code'=>1,'msg'=>'active free site','request_id'=>'fixture','server_time'=>$now,'data'=>[
        'protocol_version'=>1,'license_no'=>$ctx['license_no'],'license_version'=>$ctx['license_version'],'license_type'=>'free','site_status'=>'active',
        'domains'=>[$ctx['domain']],'machine_fingerprint_hash'=>$ctx['machine_fingerprint_hash'],'request_nonce'=>$nonce,'issued_at'=>$now,
        'expires_at'=>$now+3600,'refresh_after'=>$now+1800,'updates'=>['update_mode'=>'tenant_policy','can_update'=>false]]];
    openssl_sign(P::json($disabled),$sig,$key,OPENSSL_ALGO_SHA256);$disabled->signature=base64_encode($sig);
    Db::name('site_license_access_cache')->where('id',$cache['id'])->update(['status'=>'allowed','response_json'=>P::json($disabled),'request_nonce'=>$nonce,'expires_at'=>$now+3600]);
    $reject(fn()=>$grants->ensure($package),'known free update policy ignored','FREE_UPDATE_DISABLED');
    echo "PASS: $n transactional context, raw proof, expired-grant renewal, certificate renewal, install rejection and version selection assertions\n";
} finally {Db::rollback();@unlink($path);}
