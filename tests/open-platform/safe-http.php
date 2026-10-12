<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenSafeHttp as H;
function check($yes,$reason){if(!$yes)throw new RuntimeException($reason);}
foreach(['http://127.0.0.1/a','https://[::1]/a','https://[::ffff:127.0.0.1]/a','https://[fe80::1%25en0]/a','https://8.8.8.8:444/a','https://user:pass@8.8.8.8/a'] as $url){$rejected=false;try{H::resolve($url,false);}catch(app\common\service\open_platform\OpenException){$rejected=true;}check($rejected,'Unsafe URL accepted');}
$rejected=false;try{H::checkInputs(['reference_images'=>['http://127.0.0.1/a']]);}catch(app\common\service\open_platform\OpenException){$rejected=true;}check($rejected,'Nested numeric material array bypassed checks');
$rejected=false;try{H::checkInputs(['input'=>(object)['image_url'=>'http://127.0.0.1/a']]);}catch(app\common\service\open_platform\OpenException){$rejected=true;}check($rejected,'JSON object material bypassed checks');
check(H::redirectUrl('https://8.8.8.8/a/b','../c?x=1')==='https://8.8.8.8/c?x=1','Relative redirect');
check(H::resolve('http://8.8.8.8/a',false)===['8.8.8.8','8.8.8.8'],'Public HTTP material compatibility');
echo "PASS private destinations, nested material URLs and redirect normalization; outbound connections pin validated addresses\n";
