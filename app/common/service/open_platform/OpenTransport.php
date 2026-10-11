<?php
namespace app\common\service\open_platform;
/** Wire transport never follows redirects or forwards client credentials/cookies. */
class OpenTransport
{
    public function send(array $source,string $method,string $path,array $payload,array $files=[],?callable $chunk=null,array $clientHeaders=[]): array
    {
        $parts=parse_url((string)$source['base_url']);if(!$parts||!in_array($parts['scheme']??'',['https','http'],true)||empty($parts['host'])||isset($parts['user'],$parts['pass']))throw new OpenException('source_unavailable','上游渠道配置无效',503);
        if(!str_starts_with($path,'/api/v1/')||str_contains($path,'..')||str_contains($path,'://'))throw new OpenException('invalid_route','上游路径无效');
        $base=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.(int)$parts['port']:'');$url=$base.$path;
        $headers=['Authorization: Bearer '.$source['api_key'],'Accept: '.($clientHeaders['accept']??'application/json')];
        foreach(['anthropic-version','anthropic-beta','openai-beta','idempotency-key'] as $name)if(isset($clientHeaders[$name])&&!preg_match('/[\r\n]/',$clientHeaders[$name]))$headers[]=$name.': '.$clientHeaders[$name];
        if($method==='GET'){if($payload)$url.='?'.http_build_query($payload);$body=null;}elseif($files){$body=$payload;foreach($files as $key=>$file)$body[$key]=new \CURLFile($file->getPathname(),$file->getMime(),$file->getOriginalName());}else{$headers[]='Content-Type: application/json';$body=OpenStore::encode((object)$payload);}
        $curl=curl_init($url);$responseHeaders=[];$status=0;$responseBody='';$oversize=false;
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>min(600,max(10,(int)($source['timeout']??180))),CURLOPT_SSL_VERIFYPEER=>(bool)($source['ssl_verify']??true),CURLOPT_SSL_VERIFYHOST=>!empty($source['ssl_verify'])?2:0,CURLOPT_NOSIGNAL=>true,
            CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$responseHeaders,&$status){if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];$responseHeaders=[];}elseif(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$key=strtolower(trim($key));if(in_array($key,['content-type','retry-after','request-id','x-request-id','anthropic-ratelimit-requests-remaining'],true))$responseHeaders[$key]=trim($value);}return strlen($line);},
            CURLOPT_WRITEFUNCTION=>function($ch,$data)use(&$responseBody,&$oversize,&$responseHeaders,&$status,$chunk){if(strlen($responseBody)+strlen($data)>33554432){$oversize=true;return 0;}$responseBody.=$data;if($chunk)$chunk($data,$status,$responseHeaders);return strlen($data);}]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);$ok=curl_exec($curl);$errno=curl_errno($curl);curl_close($curl);
        if($ok===false)throw new OpenException('upstream_uncertain',$oversize?'上游响应超过限制，受理状态待确认':'上游连接中断，受理状态待确认',504);
        if($status===0)throw new OpenException('upstream_uncertain','未收到可信上游响应',504);
        return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$responseBody];
    }
}
