<?php
namespace app\common\service\open_platform;
/** Resolve and pin all outbound customer addresses. Redirects require a fresh validation. */
final class OpenSafeHttp
{
    public static function resolve(string $url,bool $httpsOnly=true): array
    {
        $parts=parse_url($url);if(!$parts||!in_array($parts['scheme']??'', $httpsOnly?['https']:['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(int)($parts['port']??(($parts['scheme']??'')==='https'?443:80))!== (($parts['scheme']??'')==='https'?443:80))throw new OpenException('invalid_url','目标地址必须是公网 HTTPS 地址');
        $host=$parts['host'];$ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:array_merge(array_column(dns_get_record($host,DNS_A)?:[],'ip'),array_column(dns_get_record($host,DNS_AAAA)?:[],'ipv6'));
        if(!$ips)throw new OpenException('invalid_url','目标域名无法解析');foreach($ips as $ip)if(!OpenPolicy::isPublicIp($ip))throw new OpenException('invalid_url','目标地址包含内部网络');return [$host,$ips[0]];
    }
    public static function post(string $url,string $body,array $headers): int
    {
        [$host,$ip]=self::resolve($url);$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static fn($ch,$bytes)=>strlen($bytes)]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);if($ok===false)throw new OpenException('callback_unavailable','通知连接失败',502);return $status;
    }
    public static function get(string $url,int $maxBytes=20971520,int $timeout=25): array
    {
        if($maxBytes<1||$maxBytes>536870912)throw new OpenException('invalid_limit','下载上限无效');
        for($redirect=0;$redirect<4;$redirect++){
            [$host,$ip]=self::resolve($url,false);$port=parse_url($url,PHP_URL_SCHEME)==='https'?443:80;$headers=[];$body='';$oversize=false;$curl=curl_init($url);
            curl_setopt_array($curl,[CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip)],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),CURLOPT_TIMEOUT=>$timeout,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'LikeAdminOpenPlatform/1.0',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){if(str_starts_with($line,'HTTP/'))$headers=[];elseif(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$headers[strtolower(trim($name))]=trim($value);}return strlen($line);},CURLOPT_WRITEFUNCTION=>function($ch,$bytes)use(&$body,&$oversize,$maxBytes){if(strlen($body)+strlen($bytes)>$maxBytes){$oversize=true;return 0;}$body.=$bytes;return strlen($bytes);}]);
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);if($ok===false)throw new OpenException('asset_fetch_failed',$oversize?'素材超出下载上限':'素材连接失败',502);
            if(in_array($status,[301,302,303,307,308],true)&&isset($headers['location'])){$url=self::redirectUrl($url,$headers['location']);continue;}
            if($status<200||$status>=300)throw new OpenException('asset_fetch_failed','素材地址返回 HTTP '.$status,502);return ['body'=>$body,'status'=>$status,'headers'=>$headers,'url'=>$url];
        }
        throw new OpenException('asset_fetch_failed','素材重定向次数超限',502);
    }
    public static function redirectUrl(string $base,string $location): string
    {
        if(preg_match('/[\x00-\x20\\\\]/',$location))throw new OpenException('invalid_url','重定向地址无效');
        if(preg_match('#^https?://#i',$location))return $location;
        $p=parse_url($base);$origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');if(str_starts_with($location,'//'))return $p['scheme'].':'.$location;
        if(str_starts_with($location,'?'))return $origin.($p['path']??'/').$location;
        $path=str_starts_with($location,'/')?$location:dirname($p['path']??'/').'/'.$location;$query='';if(str_contains($path,'?'))[$path,$query]=explode('?',$path,2);$segments=[];foreach(explode('/',$path) as $s){if($s==='..')array_pop($segments);elseif($s!==''&&$s!=='.')$segments[]=$s;}return $origin.'/'.implode('/',$segments).($query!==''?'?'.$query:'');
    }
    public static function checkInputs(array $params,string $context=''): void
    {
        foreach($params as $key=>$value){if(is_array($value)){self::checkInputs($value,is_numeric($key)?$context:(string)$key);continue;}if(is_string($value)&&preg_match('#^https?://#i',$value)&&preg_match('/(?:url|uri|image|video|audio|material)/i',(is_numeric($key)?$context:(string)$key)))self::resolve($value,false);}
    }
}
