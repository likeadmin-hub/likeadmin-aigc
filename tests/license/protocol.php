<?php
// Standalone signature and binding tests: no live credentials, licenses or application gates.
require dirname(__DIR__,2).'/app/common/service/license/SignedLicenseProtocol.php';
use app\common\service\license\SignedLicenseProtocol as P;
$key=openssl_pkey_new(['private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
$sign=static function($value)use($key){openssl_sign(P::json($value),$signature,$key,OPENSSL_ALGO_SHA256);return base64_encode($signature);};
$assert=static function($ok,$label){if(!$ok)throw new RuntimeException($label);};
$reject=static function($fn,$label){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Accepted '.$label);};
$payload=(object)['license_no'=>'LIC-test','version'=>2,'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,
    'issuer'=>(object)['platform_name'=>'签发平台','copyright'=>[]],'empty'=>(object)[],'boolean'=>false,'integer'=>123];
$certificate=(object)['payload'=>$payload,'signature'=>$sign($payload),'key_id'=>'platform-default','algorithm'=>'RSA-SHA256'];
$decoded=P::certificate(P::json($certificate),$public);
$assert(P::commercial($decoded),'commercial');
$assert(P::issuer($decoded)['copyright_provided'] && P::issuer($decoded)['copyright']===[],'empty issuer');
$reject(fn()=>P::certificate(str_replace('签发平台','伪造平台',P::json($certificate)),$public),'tampered issuer');
$assoc=json_decode(P::json($certificate),true);$reject(fn()=>P::certificate(P::json($assoc),$public),'empty object converted to array');
$assert(!P::commercial(['expires_at'=>0]),'legacy zero expiry not perpetual');
$assert(!P::commercial(['license_type'=>'commercial','is_perpetual'=>1,'expires_at'=>0]),'perpetual strict boolean');
$assert(P::httpUrl('javascript:alert(1)')==='','unsafe link');
$now=time();$context=['license_no'=>'LIC-test','license_version'=>2,'license_type'=>'commercial','domain'=>'platform.test','machine_fingerprint_hash'=>'machine','certificate_expires_at'=>0];
$data=['protocol_version'=>1,'license_no'=>'LIC-test','license_version'=>2,'license_type'=>'commercial','site_status'=>'active','domains'=>['platform.test'],
    'machine_fingerprint_hash'=>'machine','issued_at'=>$now,'expires_at'=>$now+604800,'refresh_after'=>$now+86400,'request_nonce'=>'nonce_fixture_123','apps'=>[]];
$envelope=static function($d,$code=1)use($sign,$now){$o=(object)['code'=>$code,'msg'=>'fixture','data'=>$d,'request_id'=>'fixture','server_time'=>$now,'extra'=>(object)[]];$o->signature=$sign($o);return P::json($o);};
$raw=$envelope($data);$assert(P::response($raw,$public,$context,'nonce_fixture_123',$now)['code']===1,'success full envelope');
$reject(fn()=>P::response($raw,$public,$context,'other_nonce_123',$now),'nonce replay');
foreach(['license_no'=>'other','license_version'=>3,'machine_fingerprint_hash'=>'other','domains'=>['tenant.test'],'protocol_version'=>2,
    'expires_at'=>$now+604801,'issued_at'=>$now+301,'refresh_after'=>$now+604801,'license_type'=>'free'] as $field=>$value){
    $bad=$data;$bad[$field]=$value;$reject(fn()=>P::response($envelope($bad),$public,$context,'nonce_fixture_123',$now),$field);
}
$reject(fn()=>P::response($raw,$public,$context,'nonce_fixture_123',$now+604800),'seven day boundary');
$assert(P::response($raw,$public,$context,'nonce_fixture_123',$now+3600)['data']['expires_at']===$now+604800,'cache does not renew');
$denial=['error_code'=>'LICENSE_INVALID','request_nonce'=>'nonce_fixture_123','issued_at'=>$now,'license_no'=>'LIC-test','license_version'=>2,'domain'=>'platform.test','machine_fingerprint_hash'=>'machine'];
$assert(P::response($envelope($denial,0),$public,$context,'nonce_fixture_123',$now)['data']['error_code']==='LICENSE_INVALID','signed denial');
$bad=$denial;$bad['domain']='tenant.test';$reject(fn()=>P::response($envelope($bad,0),$public,$context,'nonce_fixture_123',$now),'foreign denial');
$bad=$data;$bad['license_type']='free';$free=$context;$free['license_type']='free';$free['certificate_expires_at']=$now+100;
$reject(fn()=>P::response($envelope($bad),$public,$free,'nonce_fixture_123',$now),'free expiry cap');
echo "PASS: original JSON types, full-envelope signatures, tampering, empty copyright, nonce, certificate version, domain, machine, protocol, time, seven-day expiry, free expiry, signed denial\n";
