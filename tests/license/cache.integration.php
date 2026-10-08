<?php
// All license/source/cache/tenant fixture writes are rolled back. Remote responses are signed fixtures.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\common\service\license\SignedLicenseProtocol as P;
use app\common\service\license\SiteLicenseAccessService as S;
use app\common\service\license\CopyrightPolicyService as C;
use app\common\service\update\UpdateLicenseService as L;
use app\common\service\update\UpdateSourceClient;
use app\common\enum\AdminTerminalEnum;
use app\tenantapi\logic\setting\web\WebSettingLogic as W;

class FixtureAccess extends S {
    public $key;
    public string $mode='allow';
    public string $error='LICENSE_INVALID';
    public string $nextCertificate='';
    public bool $refreshFails=false;
    public function ctx(): array { return $this->context(); }
    public function row(): array { return $this->ensureRow($this->context()); }
    public function lateCommit(array $context,array $row,string $token,int $generation): bool {
        return $this->commit($context,$row,$token,$generation,['status'=>'allowed','expires_at'=>time()+604800]);
    }
    protected function send(string $endpoint,array $context,string $nonce): string {
        if($endpoint==='license/refresh') {
            if($this->refreshFails)throw new \app\common\service\license\LicenseTransportUnavailable('LICENSE_NETWORK_UNAVAILABLE');
            $object=(object)['code'=>1,'msg'=>'fixture','data'=>(object)['license'=>P::decode($this->nextCertificate)],'request_id'=>'refresh-fixture','server_time'=>time()];
            openssl_sign(P::json($object),$signature,$this->key,OPENSSL_ALGO_SHA256);$object->signature=base64_encode($signature);return P::json($object);
        }
        if($this->nextCertificate!=='' && $context['license_version']>=2)$this->mode='allow';
        if($this->mode==='network')throw new \app\common\service\license\LicenseTransportUnavailable('LICENSE_NETWORK_UNAVAILABLE');
        if($this->mode==='bad_signature')return '{"signature":"bad"}';
        $now=time();
        if($this->mode==='deny')$data=['error_code'=>$this->error,'request_nonce'=>$nonce,'issued_at'=>$now,'license_no'=>$context['license_no'],
            'license_version'=>$context['license_version'],'domain'=>$context['domain'],'machine_fingerprint_hash'=>$context['machine_fingerprint_hash']];
        else $data=['protocol_version'=>1,'license_no'=>$context['license_no'],'license_version'=>$context['license_version'],
            'license_type'=>$context['license_type'],'site_status'=>'active','domains'=>[$context['domain']],
            'machine_fingerprint_hash'=>$context['machine_fingerprint_hash'],'issued_at'=>$now,'expires_at'=>$now+604800,
            'refresh_after'=>$now+86400,'request_nonce'=>$nonce,'apps'=>[]];
        $object=(object)['code'=>$this->mode==='deny'?0:1,'msg'=>'fixture','data'=>$data,'request_id'=>'fixture','server_time'=>$now];
        openssl_sign(P::json($object),$signature,$this->key,OPENSSL_ALGO_SHA256);$object->signature=base64_encode($signature);
        return P::json($object);
    }
}
$assert=static function($ok,$name){if(!$ok)throw new RuntimeException($name);};
$reject=static function($fn,$name){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Accepted '.$name);};
UpdateSourceClient::getSource(); // Complete existing update-source schema repair before the rollback transaction.
$request=request();$request->source=AdminTerminalEnum::PLATFORM;
$request->withServer(array_merge($request->server(),['HTTP_HOST'=>'platform.fixture']));
$tenant=(int)Db::name('tenant')->order('id')->value('id');
Db::startTrans();
try {
    $key=openssl_pkey_new(['private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
    Db::name('update_source')->where('status',1)->update(['status'=>0]);
    Db::name('update_source')->insert(['name'=>'rollback license fixture','base_url'=>'http://fixture.invalid','license_key'=>'fixture',
        'online_base_url'=>'http://fixture.invalid','online_license_key'=>'fixture','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
    $license=new L();$machine=$license->machineCode();
    $payload=(object)['license_no'=>'LIC-rollback-fixture','version'=>1,'product_code'=>UpdateSourceClient::PRODUCT_CODE,
        'domains'=>[$machine['domain']],'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],
        'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,'update_until'=>time()-100,
        'issuer'=>(object)['platform_name'=>'fixture issuer','platform_logo'=>'javascript:bad','copyright'=>[]], 'empty'=>(object)[]];
    $envelope=static function($p)use($key){openssl_sign(P::json($p),$signature,$key,OPENSSL_ALGO_SHA256);return P::json((object)['payload'=>$p,'signature'=>base64_encode($signature),'key_id'=>'platform-default','algorithm'=>'RSA-SHA256']);};
    $raw=$envelope($payload);$license->storeCertificate($raw);
    $assert(Db::name('update_license')->order('id','desc')->value('license_json')===$raw,'original certificate changed');
    $count=Db::name('update_license')->count();
    $reject(fn()=>$license->storeCertificate(str_replace('fixture issuer','tampered issuer',$raw)),'bad import');
    $assert(Db::name('update_license')->count()===$count,'bad import replaced license');
    $license->assertSystemUpdateAllowed(); // An expired annual service does not deny every previously granted version.
    $service=new FixtureAccess();$service->key=$key;
    $assert($service->refresh(true)['can_customize'],'commercial access denied');
    $row=$service->row();$expires=(int)$row['expires_at'];
    $assert($expires>time(),'lease missing');
    $request->withServer(array_merge($request->server(),['HTTP_HOST'=>'tenant.fixture']));
    $request->source=AdminTerminalEnum::TENANT;$request->tenantId=$tenant;
    $assert((new S())->snapshot()['can_customize'],'tenant Host changed platform binding');
    $assert(C::policy()['source']==='tenant_custom','commercial tenant copyright');
    $assert(W::setCopyright(['config'=>[['key'=>'商业版自定义版权','value'=>'https://tenant.example']]])===true,'commercial save blocked');
    $assert(C::policy()['items'][0]['key']==='商业版自定义版权','commercial saved copyright not effective');
    $service->mode='network';$assert($service->refresh(true)['can_customize'],'network fallback missing');
    $assert((int)$service->row()['expires_at']===$expires,'retry renewed lease');
    $assert($service->snapshot()['access_status']==='offline','offline label');
    $service->mode='allow';$service->refresh(true);
    $service->mode='deny';$service->error='LICENSE_UPDATE_EXPIRED';
    $assert($service->refresh(true)['can_customize'],'update error revoked copyright');
    $assert($service->snapshot()['access_status']==='active','update error mislabeled as network offline');
    $service->mode='deny';$service->error='LICENSE_INVALID';$assert(!$service->refresh(true)['can_customize'],'revoke retained grant');
    $service->mode='network';$assert(!$service->refresh(true)['can_customize'],'network resurrected revoked grant');
    $assert(C::policy()['source']==='license_issuer' && C::policy()['items']===[],'empty issuer fallback');
    $assert(W::setCopyright(['config'=>[['key'=>'bypass','value'=>'']]])===false,'direct save bypass');
    $service->mode='allow';$assert($service->refresh(true)['can_customize'],'recovery failed');
    $assert(C::policy()['items'][0]['key']==='商业版自定义版权','previous custom copyright lost after recovery');
    $service->mode='deny';$service->error='INVALID_API_KEY';$assert(!$service->refresh(true)['can_customize'],'auth error fallback');
    $service->mode='allow';$service->refresh(true);
    $service->mode='bad_signature';$assert(!$service->refresh(true)['can_customize'],'bad signature fallback');
    $service->mode='allow';$service->refresh(true);
    $row=$service->row();$ctx=$service->ctx();$token='fixture_older_token';
    Db::name('site_license_access_cache')->where('id',$row['id'])->update(['lock_token'=>$token]);
    S::invalidate();
    $assert(!$service->lateCommit($ctx,$row,$token,(int)$row['generation']),'late allow restored invalidation');
    $assert(!$service->snapshot()['can_customize'],'invalidation ignored');
    $service->refresh(true);
    Db::name('site_license_access_cache')->where('context_key',$service->ctx()['context_key'])->update(['expires_at'=>time()]);
    $assert(!$service->snapshot()['can_customize'],'expiration boundary');
    $service->refresh(true);
    Db::name('update_source')->where('status',1)->update(['license_key'=>'changed credential']);
    $assert(!$service->snapshot()['can_customize'],'credential cache isolation');
    Db::name('update_source')->where('status',1)->update(['license_key'=>'fixture']);
    foreach(['LICENSE_DOMAIN_FORBIDDEN','LICENSE_MACHINE_FORBIDDEN','LICENSE_IP_FORBIDDEN','COMMERCIAL_LICENSE_REQUIRED','LICENSE_EXPIRED'] as $error) {
        $service->mode='allow';$service->refresh(true);$service->mode='deny';$service->error=$error;
        $assert(!$service->refresh(true)['can_customize'],'binding/qualification denial ignored: '.$error);
    }
    $service->mode='allow';$service->refresh(true);
    $old=$service->row();$oldContext=$service->ctx();$oldToken='older_success';
    Db::name('site_license_access_cache')->where('id',$old['id'])->update(['lock_token'=>$oldToken]);
    $service->mode='deny';$service->error='LICENSE_INVALID';$service->refresh(true);
    $assert(!$service->lateCommit($oldContext,$old,$oldToken,(int)$old['generation']),'late success restored signed denial');
    // Failed refresh must never resurrect the previously allowed lease.
    $service->mode='allow';$service->refresh(true);$service->mode='deny';$service->error='LICENSE_REDOWNLOAD_REQUIRED';
    $service->refreshFails=true;$assert(!$service->refresh(true)['can_customize'],'failed certificate refresh resurrected old lease');
    $service->refreshFails=false;$replacement=clone $payload;$replacement->version=2;
    $replacement->issuer=(object)['platform_name'=>'refreshed issuer','copyright'=>[(object)['text'=>'更新后的签发版权','url'=>'https://issuer.example']]];
    $service->nextCertificate=$envelope($replacement);
    $assert($service->refresh(true)['can_customize'],'certificate refresh + access failed');
    $assert($license->verifiedSiteContext()['license_version']===2,'certificate version not refreshed');
    $assert($service->snapshot()['issuer']['copyright'][0]['key']==='更新后的签发版权','issuer not refreshed');
    $service->nextCertificate='';
    // Origin switches must not reuse a qualified lease.
    Db::name('update_source')->where('status',1)->update(['base_url'=>'http://other-fixture.invalid']);
    $assert(!$service->snapshot()['can_customize'],'source origin cache isolation');
    Db::name('update_source')->where('status',1)->update(['base_url'=>'http://fixture.invalid']);
    // Different trusted key cannot reuse issuer or commercial state.
    Db::name('update_source')->where('status',1)->update(['public_key'=>openssl_pkey_get_details(openssl_pkey_new(['private_key_bits'=>2048]))['key']]);
    $assert(C::policy()['source']==='builtin','untrusted issuer fallback');
    echo "PASS: atomic original import, invalid import preservation, update deadline isolation, platform/tenant domains, copyright guard, empty issuer, network cache, revocation, auth/signature failure, recovery, expiry, late success after denial, certificate refresh and issuer update, source/credential/key isolation\n";
} finally { Db::rollback(); }
