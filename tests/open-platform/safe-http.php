<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenSafeHttp as H;
use app\common\service\open_platform\OpenPolicy as P;
function check($yes,$reason){if(!$yes)throw new RuntimeException($reason);}
$blocked = [
    // Both boundaries of shared/benchmark/documentation ranges, plus cloud services.
    '100.64.0.0','100.127.255.255','100.100.100.200','168.63.129.16',
    '0.0.0.0','0.255.255.255','10.0.0.1','127.0.0.1','169.254.169.254',
    '172.16.0.0','172.31.255.255','192.0.0.1','192.0.2.0','192.0.2.255',
    '192.88.99.0','192.88.99.255','192.168.0.1','198.18.0.0','198.19.255.255',
    '198.51.100.0','198.51.100.255','203.0.113.0','203.0.113.255',
    '224.0.0.0','239.255.255.255','240.0.0.0','255.255.255.255',
    '::','::1','fc00::1','fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff',
    'fe80::1','febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff','fec0::1','ff02::1','ffff::1',
    '100::1','100:0:0:1::1','2001::1','2001:1ff:ffff:ffff:ffff:ffff:ffff:ffff',
    '2001:2::1','2001:10::1','2001:20::1','2001:db8::','2001:db8:ffff:ffff:ffff:ffff:ffff:ffff',
    '3fff::','3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff','5f00::1',
    // Standard transition prefixes are unsupported, even when the embedded IPv4 is public.
    '::127.0.0.1','::808:808','64:ff9b::7f00:1','64:ff9b::808:808',
    '64:ff9b:1::a00:1','2002:7f00:1::','2002:808:808::',
    '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    // IPv4-mapped addresses must be checked by their packed value, not spelling.
    '::ffff:127.0.0.1','::ffff:7f00:1','0:0:0:0:0:ffff:7f00:1',
    '::FFFF:A00:1','::ffff:6440:0','::ffff:647f:ffff','::ffff:a9fe:a9fe',
    '::ffff:e000:1','::ffff:c633:6401',
];
foreach ($blocked as $ip) check(!P::isPublicIp($ip), 'Non-public address accepted: '.$ip);
$public = [
    '1.1.1.1','8.8.8.8','100.63.255.255','100.128.0.0','168.63.129.15','168.63.129.17',
    '172.15.255.255','172.32.0.0','192.0.1.255','192.0.3.0','192.88.98.255','192.88.100.0',
    '198.17.255.255','198.20.0.0','198.51.99.255','198.51.101.0','203.0.112.255','203.0.114.0','223.255.255.255',
    '2001:200::1','2001:4860:4860::8888','2400:3200::1','2606:4700:4700::1111','2620:fe::fe',
    '2001:db7:ffff:ffff:ffff:ffff:ffff:ffff','2001:db9::','3ffe:ffff:ffff:ffff:ffff:ffff:ffff:ffff','3fff:1000::',
    '::ffff:8.8.8.8','::ffff:808:808','0:0:0:0:0:FFFF:0808:0808','::ffff:643f:ffff','::ffff:6480:0',
];
foreach ($public as $ip) check(P::isPublicIp($ip), 'Global unicast address rejected: '.$ip);
foreach (['','not-an-ip','127.1','2130706433','0x7f000001','8.8.8.8 ','[::1]','fe80::1%en0',"8.8.8.8\0"] as $ip) {
    check(!P::isPublicIp($ip), 'Invalid IP accepted: '.json_encode($ip));
}
foreach(['http://127.0.0.1/a','https://[::1]/a','https://[::ffff:127.0.0.1]/a','https://[fe80::1%25en0]/a','https://8.8.8.8:444/a','https://user:pass@8.8.8.8/a'] as $url){$rejected=false;try{H::resolve($url,false);}catch(app\common\service\open_platform\OpenException){$rejected=true;}check($rejected,'Unsafe URL accepted');}
$rejected=false;try{H::checkInputs(['reference_images'=>['http://127.0.0.1/a']]);}catch(app\common\service\open_platform\OpenException){$rejected=true;}check($rejected,'Nested numeric material array bypassed checks');
$rejected=false;try{H::checkInputs(['input'=>(object)['image_url'=>'http://127.0.0.1/a']]);}catch(app\common\service\open_platform\OpenException){$rejected=true;}check($rejected,'JSON object material bypassed checks');
check(H::redirectUrl('https://8.8.8.8/a/b','../c?x=1')==='https://8.8.8.8/c?x=1','Relative redirect');
check(H::resolve('http://8.8.8.8/a',false)===['8.8.8.8','8.8.8.8'],'Public HTTP material compatibility');
foreach (['http://100.100.100.200/a','http://168.63.129.16/a','https://224.0.0.1/a','https://[::ffff:7f00:1]/a','https://[64:ff9b::a00:1]/a','https://[2002:7f00:1::]/a'] as $url) {
    $rejected=false;try{H::resolve($url,false);}catch(app\common\service\open_platform\OpenException){$rejected=true;}
    check($rejected,'Special or translated destination accepted: '.$url);
}
check(H::resolve('https://[2606:4700:4700::1111]/a')===['2606:4700:4700::1111','2606:4700:4700::1111'],'Native public IPv6 compatibility');
echo 'PASS global-unicast policy: '.count($blocked).' rejected, '.count($public).' accepted, invalid forms and real URL boundaries checked'."\n";
echo "PASS private destinations, nested material URLs and redirect normalization; outbound connections pin validated addresses\n";
