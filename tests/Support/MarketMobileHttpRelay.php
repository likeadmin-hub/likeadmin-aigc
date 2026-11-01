<?php
/** CLI-only host/mobile bridge. Restricted to a live owned private loopback fixture. */
if (PHP_SAPI !== 'cli') exit(1);
$input=json_decode(file_get_contents('php://stdin'),true);$dir=$input['owner']??'';
if(!preg_match('~^/tmp/(b56_[a-f0-9]{12}_)fixture$~D',$dir,$match)||!is_file($dir.'/owner')||trim(file_get_contents($dir.'/owner'))!==$match[1])exit(2);
$action=$input['action']??'';$name=$input['name']??'';
if(in_array($action,['read','write'],true)) {
    if(!preg_match('/^mobile-[a-z-]+\.json$/D',$name))exit(3);
    if($action==='write'){file_put_contents($dir.'/'.$name.'.tmp',json_encode($input['data'],JSON_THROW_ON_ERROR));rename($dir.'/'.$name.'.tmp',$dir.'/'.$name);echo 'true';}
    else echo is_file($dir.'/'.$name)?file_get_contents($dir.'/'.$name):'null';
    exit;
}
if($action==='download') {
    $url=(string)($input['url']??'');$path=parse_url($url,PHP_URL_PATH);
    if(parse_url($url,PHP_URL_HOST)!=='private-media.example.test'||!is_string($path)||!str_starts_with($path,'/uploads/'))exit(10);
    $file=realpath($dir.'/public'.$path);$root=realpath($dir.'/public/uploads');
    if(!$file||!$root||!str_starts_with($file,$root.'/')||strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='docx')exit(11);
    $curl=curl_init(trim(file_get_contents($dir.'/supplier-url')).$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);if(curl_errno($curl))exit(12);curl_close($curl);
    echo json_encode(['statusCode'=>$status,'bytes'=>base64_encode($bytes)],JSON_THROW_ON_ERROR);exit;
}
if(!in_array($action,['http','upload'],true)||!is_file($dir.'/mobile-input.json'))exit(4);
$fixture=json_decode(file_get_contents($dir.'/mobile-input.json'),true);$url=$input['url']??'';$allowed=false;
foreach([$fixture['api'],$fixture['api_peer']]as$base)if(str_starts_with($url,$base.'/api/app.')||(!empty($fixture['ui'])&&parse_url($url,PHP_URL_PATH)==='/api/login/account'&&($input['method']??'')==='POST'&&str_starts_with($url,$base.'/api/')))$allowed=true;
if(!$allowed||parse_url($url,PHP_URL_HOST)!=='127.0.0.1')exit(5);
$headers=$action==='upload'?[]:['Content-Type: application/json'];foreach($input['header']??[]as$key=>$value){if(!preg_match('/^[A-Za-z0-9_-]+$/D',$key)||strpbrk((string)$value,"\r\n")!==false)exit(6);$headers[]=$key.': '.$value;}
$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
if($action==='upload') {
    if(parse_url($url,PHP_URL_PATH)!=='/api/app.aigc_llm.agent/upload')exit(8);
    $bytes=base64_decode($input['bytes']??'',true);$filename=(string)($input['filename']??'reference.png');
    if($bytes===false||strlen($bytes)>50*1024*1024||strpbrk($filename,"\r\n\0")!==false||basename($filename)!==$filename)exit(9);
    $temporary=tempnam($dir.'/tmp','mobile-upload-');file_put_contents($temporary,$bytes);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($temporary,(string)($input['mime']??'application/octet-stream'),$filename)]]);
}
elseif(($input['method']??'GET')==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($input['data']??[],JSON_THROW_ON_ERROR)]);
else if(!empty($input['data']))curl_setopt($curl,CURLOPT_URL,$url.(str_contains($url,'?')?'&':'?').http_build_query($input['data']));
$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);if(isset($temporary))unlink($temporary);if(curl_errno($curl))exit(7);curl_close($curl);
echo json_encode(['statusCode'=>$status,'data'=>json_decode((string)$body,true,512,JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR);
