<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenTransport;
$t=new OpenTransport;$s=['base_url'=>'http://127.0.0.1:19873','api_key'=>'fixture-server-key','ssl_verify'=>true];
function ok($yes,$why){if(!$yes)throw new RuntimeException($why);}
$input=['model'=>'fixture','messages'=>[['role'=>'user','content'=>'fixture']]];$r=$t->send($s,'POST','/api/v1/chat/completions',$input);$d=json_decode($r['body'],true);ok($d['received']===$input&&$d['authorization_replaced'],'Wire payload or server credential replacement');
$chunks='';$r=$t->send($s,'POST','/api/v1/responses',[],[],function($bytes)use(&$chunks){$chunks.=$bytes;});ok($chunks===$r['body']&&str_contains($chunks,'event: response.created'),'SSE bytes changed');
$r=$t->send($s,'POST','/api/v1/messages',[]);ok($r['status']===429&&$r['headers']['retry-after']==='5','Upstream status or retry header changed');
$r=$t->send($s,'GET','/api/v1/files/up-fixture',[]);ok($r['body']==="\x00fixture\xff"&&$r['headers']['content-type']==='application/octet-stream','Binary bytes changed');
echo "PASS loopback HTTP payload, credential replacement, SSE bytes, HTTP errors and binary transport\n";
