<?php
declare(strict_types=1);
namespace app\common\service\brand {
    /** Only the external DNS/cURL transport is simulated; production verification runs unchanged. */
    final class ProbeTransport { public static array $dns=[],$options=[];public static string $url='', $body='';public static int $status=200;public static bool $tls=true; }
    function dns_get_record(string $host,int $type):array{return ProbeTransport::$dns[$host][$type]??[];}
    function curl_init(string $url):object{ProbeTransport::$url=$url;return (object)[];}
    function curl_setopt_array($curl,array $options):bool{ProbeTransport::$options=$options;return true;}
    function curl_exec($curl):bool{return ProbeTransport::$tls && (ProbeTransport::$options[CURLOPT_WRITEFUNCTION])($curl,ProbeTransport::$body)===strlen(ProbeTransport::$body);}
    function curl_getinfo($curl,int $option):int{return ProbeTransport::$status;}
    function curl_close($curl):void{}
}
namespace {
    require __DIR__.'/../vendor/autoload.php';
    use app\common\service\brand\BrandDomainProbe;
    use app\common\service\brand\ProbeTransport as T;
    function probeCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
    function resetProbe():void{T::$dns=['_oem-brand.brand.example.com'=>[DNS_TXT=>[['txt'=>'oem=fixture-token']]],'brand.example.com'=>[DNS_A=>[['ip'=>'1.2.3.4']]],'platform.example.com'=>[DNS_A=>[['ip'=>'1.2.3.4']]]];T::$tls=true;T::$status=200;T::$body='{"oem_challenge":"fixture-token"}';}
    $probe=new BrandDomainProbe();
    foreach(['wrong-txt','missing-dns','private-address','mixed-private-ipv6','wrong-origin','invalid-tls','redirect','wrong-body','oversized-body'] as $case){resetProbe();switch($case){
        case 'wrong-txt':T::$dns['_oem-brand.brand.example.com'][DNS_TXT]=[['txt'=>'other']];break;
        case 'missing-dns':T::$dns['brand.example.com']=[];break;
        case 'private-address':T::$dns['brand.example.com'][DNS_A]=[['ip'=>'127.0.0.1']];break;
        case 'mixed-private-ipv6':T::$dns['brand.example.com'][DNS_AAAA]=[['ipv6'=>'::1']];break;
        case 'wrong-origin':T::$dns['brand.example.com'][DNS_A]=[['ip'=>'8.8.8.8']];break;
        case 'invalid-tls':T::$tls=false;break;
        case 'redirect':T::$status=302;break;
        case 'wrong-body':T::$body='{"oem_challenge":"other"}';break;
        case 'oversized-body':T::$body=str_repeat('x',4097);break;
    }$failed=false;try{$probe->verify('brand.example.com','fixture-token','platform.example.com');}catch(\app\common\service\brand\BrandUserException $e){$failed=true;}probeCheck($failed,'Transport proof accepted '.$case);}
    resetProbe();$probe->verify('brand.example.com','fixture-token','platform.example.com');
    probeCheck(T::$options[CURLOPT_RESOLVE]===['brand.example.com:443:1.2.3.4'],'DNS must pin HTTPS address');
    probeCheck(T::$options[CURLOPT_SSL_VERIFYPEER]===true && T::$options[CURLOPT_SSL_VERIFYHOST]===2 && T::$options[CURLOPT_FOLLOWLOCATION]===false && T::$options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS && T::$options[CURLOPT_PROXY]==='' && T::$options[CURLOPT_TIMEOUT]===8,'HTTPS security/time bounds changed');
    echo "PASS actual DNS/TLS probe algorithm: TXT, all-address/origin checks, HTTPS pinning, TLS failure, redirect, challenge and body limits; external transport simulated\n";
}
