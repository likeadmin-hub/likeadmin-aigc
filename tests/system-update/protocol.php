<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\license\SignedLicenseProtocol as P;
use app\common\service\update\SystemUpdateProtocol as U;
use app\common\service\update\UpdateProtocolException;
$key=openssl_pkey_new(['private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
$n=0;$assert=static function($ok,$name)use(&$n){if(!$ok)throw new RuntimeException($name);$n++;};
$reject=static function($fn,$name,$code='')use($assert){try{$fn();}catch(Throwable $e){$assert($code===''||($e instanceof UpdateProtocolException&&$e->errorCode===$code),$name.' wrong error '.$e->getMessage());return;}throw new RuntimeException('Accepted '.$name);};
$now=time();$context=['license_no'=>'LIC-fixture','license_version'=>3,'domain'=>'platform.fixture','machine_fingerprint_hash'=>'machine','ip'=>'','raw_certificate'=>'signed-fixture'];
$hash=hash('sha256','fixture archive');
$data=['product_code'=>'likeadmin_aigc_saas','version'=>'1.0.386','target_version'=>'1.0.386','sha256'=>$hash,'format'=>'zip',
    'site_grant'=>['license_no'=>'LIC-fixture','license_version'=>3,'domains'=>['platform.fixture'],'machine_fingerprint_hash'=>'machine','version_id'=>100,'issued_at'=>$now,'expires_at'=>$now+3600]];
$sign=static function($data,$time=null)use($now,$key){$o=(object)['code'=>1,'msg'=>'fixture','data'=>$data,'request_id'=>'fixture','server_time'=>$time??$now,'extra'=>(object)[]];openssl_sign(P::json($o),$sig,$key,OPENSSL_ALGO_SHA256);$o->signature=base64_encode($sig);return P::json($o);};
$raw=$sign($data);$assert(U::package($raw,$public,$context,'1.0.386',$hash,'zip',$now)['site_grant']['version_id']===100,'signed original envelope');
$reject(fn()=>U::package(str_replace('1.0.386','1.0.387',$raw),$public,$context,'1.0.387'),'tampered target');
$reject(fn()=>U::package($raw,$public,$context,'1.0.386',$hash,'zip',$now+3600),'exclusive one hour boundary','SYSTEM_PACKAGE_GRANT_EXPIRED');
$assert(U::package($raw,$public,$context,'1.0.386',$hash,'zip',$now+1800)['site_grant']['expires_at']===$now+3600,'reading never extends grant');
foreach(['license_no'=>'other','license_version'=>4,'domains'=>['tenant.fixture'],'machine_fingerprint_hash'=>'foreign','version_id'=>0,'expires_at'=>$now+3601,'issued_at'=>$now+301] as $field=>$value){$bad=$data;$bad['site_grant'][$field]=$value;$reject(fn()=>U::package($sign($bad),$public,$context,'1.0.386',$hash,'zip',$now),$field,'SYSTEM_PACKAGE_GRANT_INVALID');}
$reject(fn()=>U::package($raw,$public,$context,'1.0.387',$hash,'zip',$now),'target mismatch','SYSTEM_PACKAGE_GRANT_INVALID');
$reject(fn()=>U::package($raw,$public,$context,'1.0.386',hash('sha256','tampered'),'zip',$now),'file hash mismatch','SYSTEM_PACKAGE_HASH_MISMATCH');
$reject(fn()=>U::package($raw,$public,$context,'1.0.386',$hash,'tar.gz',$now),'format mismatch','SYSTEM_PACKAGE_HASH_MISMATCH');
$bad=$data;$bad['fallback_url']='https://fixture.invalid/fallback';$reject(fn()=>U::package($sign($bad),$public,$context,'1.0.386'),'unsigned fallback hash','SYSTEM_PACKAGE_HASH_MISMATCH');
$bad['fallback_sha256']=hash('sha256','fallback');$bad['fallback_format']='tar.gz';$assert(U::package($sign($bad),$public,$context,'1.0.386',$bad['fallback_sha256'],'tar.gz')['version']==='1.0.386','signed fallback');
$commercial=['schema_version'=>3,'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,'update_mode'=>'annual','update_until'=>$now];
$assert(P::commercial($commercial),'annual expiry retains permanent commercial eligibility');
$online=['update_mode'=>'annual','update_until'=>$now,'update_rights_revision'=>5,'can_update'=>true];
$state=U::updates($commercial,$online,$now);$assert(!$state['can_update']&&$state['update_state']==='expired','access cache cannot extend annual service');
$assert(U::updates(['update_until'=>0],[],$now)['update_mode']==='legacy','zero does not mean lifetime');
$assert(!U::updates(['update_mode'=>'lifetime'],[],$now)['can_update'],'certificate display cannot authorize a package');
$assert(U::updates([],['update_mode'=>'lifetime','update_until'=>0,'can_update'=>true],$now)['update_state']==='active','explicit lifetime');
$assert(U::updates([],['update_mode'=>'tenant_policy','can_update'=>false],$now)['update_state']==='disabled','free update disabled');
$source=['active_base_url'=>'https://source.fixture','active_api_key'=>'fixture','public_key'=>$public];$first=U::contextKey($context,$source);
foreach(['active_api_key'=>'changed','active_base_url'=>'https://changed.fixture','public_key'=>'changed'] as $field=>$value){$copy=$source;$copy[$field]=$value;$assert(U::contextKey($context,$copy)!==$first,$field.' isolation');}
$copy=$context;$copy['raw_certificate']='renewed';$assert(U::contextKey($copy,$source)!==$first,'certificate isolation');
echo "PASS: $n system signature, binding, expiry, checksum, fallback and update-service boundary assertions\n";
