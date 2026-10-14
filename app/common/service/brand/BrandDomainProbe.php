<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\service\open_platform\OpenPolicy;
/** DNS and TLS are the external boundary. DNS is pinned again for the HTTPS probe. */
class BrandDomainProbe
{
    public function verify(string $domain,string $token,string $target): void
    {
        $txt=dns_get_record('_oem-brand.'.$domain,DNS_TXT)?:[];
        if (!in_array('oem='.$token,array_column($txt,'txt'),true)) throw new BrandUserException('域名归属尚未验证，请添加提示的 TXT 记录后重试');
        $records=array_merge(dns_get_record($domain,DNS_A)?:[],dns_get_record($domain,DNS_AAAA)?:[]);
        $ips=array_merge(array_column($records,'ip'),array_column($records,'ipv6'));
        $origin=array_merge(array_column(dns_get_record($target,DNS_A)?:[],'ip'),array_column(dns_get_record($target,DNS_AAAA)?:[],'ipv6'));
        if (!$ips || !$origin) throw new BrandUserException('域名解析尚未生效，请指向提示的站点域名');
        foreach ($ips as $ip) if (!OpenPolicy::isPublicIp($ip) || !in_array($ip,$origin,true)) throw new BrandUserException('域名解析需要指向本站公网地址，请检查 A、AAAA 或 CNAME 记录');
        $ip=$ips[0]; $body='';
        $curl=curl_init('https://'.$domain.'/api/brand.domain/probe?challenge='.rawurlencode($token));
        curl_setopt_array($curl,[CURLOPT_RESOLVE=>[$domain.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>static function($ch,$bytes)use(&$body) { if(strlen($body)+strlen($bytes)>4096) return 0; $body.=$bytes; return strlen($bytes); }]);
        $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        $data=json_decode($body,true);
        if ($ok===false || $status!==200 || !is_array($data) || !hash_equals($token,(string)($data['oem_challenge']??''))) throw new BrandUserException('HTTPS 或站点绑定尚未完成，请配置有效证书并将域名绑定本站后重试');
    }
}
