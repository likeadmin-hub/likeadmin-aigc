<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenTransport;
$t=new OpenTransport;$s=['base_url'=>'http://127.0.0.1:19873','api_key'=>'fixture-server-key','ssl_verify'=>true];
function ok($yes,$why){if(!$yes)throw new RuntimeException($why);}
$input=['model'=>'fixture','messages'=>[['role'=>'user','content'=>'fixture']]];$r=$t->send($s,'POST','/api/v1/chat/completions',$input);$d=json_decode($r['body'],true);ok($d['received']===$input&&$d['authorization_replaced'],'Wire payload or server credential replacement');
$shaped=['metadata'=>(object)[],'items'=>[],'numeric_object'=>(object)['0'=>'zero']];$r=$t->send($s,'POST','/api/v1/fixture-json',$shaped);$shape=json_decode($r['body']);ok($shape->received->metadata instanceof \stdClass&&is_array($shape->received->items)&&$shape->received->numeric_object instanceof \stdClass,'JSON object/array wire shape changed');
$chunks='';$r=$t->send($s,'POST','/api/v1/responses',[],[],function($bytes)use(&$chunks){$chunks.=$bytes;});ok($chunks===$r['body']&&str_contains($chunks,'event: response.created'),'SSE bytes changed');
$r=$t->send($s,'POST','/api/v1/messages',[]);ok($r['status']===429&&$r['headers']['retry-after']==='5','Upstream status or retry header changed');
$r=$t->send($s,'GET','/api/v1/files/up-fixture',[]);ok($r['body']==="\x00fixture\xff"&&$r['headers']['content-type']==='application/octet-stream','Binary bytes changed');
$filePath=tempnam(sys_get_temp_dir(),'open-transport-');file_put_contents($filePath,'owned-upload-fixture');
try{
    $file=new \think\file\UploadedFile($filePath,'reviewed.txt','text/plain',UPLOAD_ERR_OK,true);
    $payload=['quantity'=>2,'options'=>['speed'=>1.5],'enabled'=>false,'prompt'=>'@literal'];
    $r=$t->send($s,'POST','/api/v1/fixture-upload',$payload,['file'=>$file],null,[],'multipart/form-data');$d=json_decode($r['body'],true);
    ok($d['multipart']&&$d['form']===['quantity'=>'2','options'=>'{"speed":1.5}','enabled'=>'false','prompt'=>'@literal']&&$d['files']['file']['sha256']===hash_file('sha256',$filePath),'Multipart fields or upload bytes changed');
    $r=$t->send($s,'POST','/api/v1/fixture-upload',['quantity'=>2],[],null,[],'multipart/form-data');$d=json_decode($r['body'],true);
    ok($d['multipart']&&$d['form']===['quantity'=>'2']&&$d['files']===[],'Multipart without files silently became JSON');
}finally{unlink($filePath);}
echo "PASS loopback HTTP payload, credential replacement, SSE bytes, HTTP errors and binary transport plus typed multipart with and without files\n";
