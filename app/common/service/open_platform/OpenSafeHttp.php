<?php
namespace app\common\service\open_platform;
/** Resolve and pin all outbound customer addresses. Redirects require a fresh validation. */
final class OpenSafeHttp
{
    public static function resolve(string $url): array
    {
        $parts=parse_url($url);if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||!in_array((int)($parts['port']??443),[443],true))throw new OpenException('invalid_url','目标地址必须是公网 HTTPS 地址');
        $host=$parts['host'];$ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:array_merge(array_column(dns_get_record($host,DNS_A)?:[],'ip'),array_column(dns_get_record($host,DNS_AAAA)?:[],'ipv6'));
        if(!$ips)throw new OpenException('invalid_url','目标域名无法解析');foreach($ips as $ip)if(!OpenPolicy::isPublicIp($ip))throw new OpenException('invalid_url','目标地址包含内部网络');return [$host,$ips[0]];
    }
    public static function post(string $url,string $body,array $headers): int
    {
        [$host,$ip]=self::resolve($url);$curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static fn($ch,$bytes)=>strlen($bytes)]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);if($ok===false)throw new OpenException('callback_unavailable','通知连接失败',502);return $status;
    }
    public static function checkInputs(array $params): void
    {
        foreach($params as $key=>$value){if(is_array($value)){self::checkInputs($value);continue;}if(is_string($value)&&preg_match('#^https?://#i',$value)&&preg_match('/(?:url|uri|image|video|audio|material)/i',(string)$key))self::resolve($value);}
    }
}
