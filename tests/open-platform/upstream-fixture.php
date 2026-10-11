<?php
// Loopback-only contract fixture. Never use this as an upstream production service.
if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/api/v1/tasks/music-operation-')){
    header('Content-Type: application/json');$id=basename($path);
    if(str_ends_with($id,'query-error')){http_response_code(503);echo '{"code":0,"msg":"fixture query unavailable"}';exit;}
    $status=str_ends_with($id,'completed')?'completed':(str_ends_with($id,'failed')?'failed':'processing');
    if(str_ends_with($id,'outer-completed')){echo json_encode(['code'=>1,'data'=>['task_id'=>$id,'status'=>'completed','result'=>['success'=>true,'file_url'=>'https://example.com/fixture.wav','data'=>[['id'=>'fixture-vox']]]]]);exit;}
    echo json_encode(['code'=>1,'data'=>['result'=>['task_id'=>$id,'status'=>$status,'file_url'=>'https://example.com/fixture.wav','data'=>['id'=>'fixture-vox']]]]);exit;
}
if($path==='/api/v1/music-receipt'){header('Content-Type: application/json');echo '{"code":1,"data":{"task_id":"fixture-music-receipt","receipt":"private fixture receipt"}}';exit;}
if($path==='/api/v1/music-no-task'){header('Content-Type: application/json');echo '{"code":1,"data":{}}';exit;}
if($path==='/api/v1/music-reject'){http_response_code(403);header('Content-Type: application/json');echo '{"code":0,"msg":"Invalid parameter"}';exit;}
if($path==='/api/v1/music-query-failure'){http_response_code(503);header('Content-Type: application/json');echo '{"code":0,"msg":"Query temporarily unavailable"}';exit;}
if($path==='/api/v1/responses') {header('Content-Type: text/event-stream');echo "event: response.created\ndata: {\"id\":\"up-fixture\"}\n\n";flush();usleep(20000);echo "data: {\"response\":{\"usage\":{\"input_tokens\":10,\"output_tokens\":5}}}\n\n";exit;}
if($path==='/api/v1/messages'){http_response_code(429);header('Content-Type: application/json');header('Retry-After: 5');echo '{"type":"error","error":{"type":"rate_limit_error","message":"fixture"}}';exit;}
if($path==='/api/v1/files/up-fixture'){header('Content-Type: application/octet-stream');echo "\x00fixture\xff";exit;}
if($path==='/api/v1/fixture-json'){header('Content-Type: application/json');echo json_encode(['received'=>json_decode(file_get_contents('php://input'))]);exit;}
if($path==='/api/v1/fixture-upload'){header('Content-Type: application/json');$files=[];foreach($_FILES as $name=>$file)$files[$name]=['name'=>$file['name'],'sha256'=>hash_file('sha256',$file['tmp_name'])];echo json_encode(['form'=>$_POST,'files'=>$files,'multipart'=>str_starts_with($_SERVER['CONTENT_TYPE']??'','multipart/form-data; boundary=')]);exit;}
header('Content-Type: application/json');$body=json_decode(file_get_contents('php://input'),true)?:[];echo json_encode(['id'=>'fixture','received'=>$body,'authorization_replaced'=>($_SERVER['HTTP_AUTHORIZATION']??'')==='Bearer fixture-server-key','usage'=>['total_tokens'=>15]]);
