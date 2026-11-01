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
if($action!=='http'||!is_file($dir.'/mobile-input.json'))exit(4);
$fixture=json_decode(file_get_contents($dir.'/mobile-input.json'),true);$url=$input['url']??'';$allowed=false;
foreach([$fixture['api'],$fixture['api_peer']]as$base)if(str_starts_with($url,$base.'/api/app.')||(!empty($fixture['ui'])&&parse_url($url,PHP_URL_PATH)==='/api/login/account'&&($input['method']??'')==='POST'&&str_starts_with($url,$base.'/api/')))$allowed=true;
if(!$allowed||parse_url($url,PHP_URL_HOST)!=='127.0.0.1')exit(5);
$headers=['Content-Type: application/json'];foreach($input['header']??[]as$key=>$value){if(!preg_match('/^[A-Za-z0-9_-]+$/D',$key)||strpbrk((string)$value,"\r\n")!==false)exit(6);$headers[]=$key.': '.$value;}
$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
if(($input['method']??'GET')==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($input['data']??[],JSON_THROW_ON_ERROR)]);
else if(!empty($input['data']))curl_setopt($curl,CURLOPT_URL,$url.'?'.http_build_query($input['data']));
$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);if(curl_errno($curl))exit(7);curl_close($curl);
echo json_encode(['statusCode'=>$status,'data'=>json_decode((string)$body,true,512,JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR);
