<?php
declare(strict_types=1);
namespace app\common\service\wechat;

/** Public domain lists only; account credentials and runtime API routing are separate. */
final class WechatDomainSettings
{
    public const MINI_FIELDS=['request_domain'=>'https','socket_domain'=>'wss','upload_file_domain'=>'https','download_file_domain'=>'https','udp_domain'=>'udp','business_domain'=>''];
    public const OFFICIAL_FIELDS=['business_domain'=>'','js_secure_domain'=>'','web_auth_domain'=>''];

    public static function normalize(array $data,string $type):array
    {
        $result=[];
        foreach($type==='official'?self::OFFICIAL_FIELDS:self::MINI_FIELDS as $field=>$scheme){
            if(!array_key_exists($field,$data))continue;
            $value=$data[$field];
            if(!is_string($value)&&!is_array($value))throw new \InvalidArgumentException($field.'：域名格式错误');
            if(is_array($value)){if(!array_is_list($value)||array_filter($value,static fn($v)=>!is_string($v)))throw new \InvalidArgumentException($field.'：每项须为域名字符串');$value=implode("\n",$value);}
            if(strlen($value)>20000)throw new \InvalidArgumentException($field.'：域名列表过长');
            $items=preg_split('/[\s,;，；]+/u',trim($value),-1,PREG_SPLIT_NO_EMPTY);$domains=[];
            if(count($items)>100)throw new \InvalidArgumentException($field.'：一次最多填写100个域名');
            foreach($items as $item){
                $url=str_contains($item,'://')?$item:($scheme?:'https').'://'.$item;
                $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));
                if(!$parts||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)||isset($parts['port'],$parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['path']??'',['','/'],true)||!in_array(strtolower($parts['scheme']??''),$scheme!==''?[$scheme]:['http','https'],true))throw new \InvalidArgumentException($field.'：请填写'.($scheme?$scheme.'://':'').'域名，不含端口、路径或参数');
                $domains[]=$scheme!==''?$scheme.'://'.$host:$host;
            }
            $result[$field]=implode("\n",array_values(array_unique($domains)));
        }
        return $result;
    }

    public static function miniProgram(string $apiBaseUrl,array $settings):array
    {
        $host=(string)parse_url($apiBaseUrl,PHP_URL_HOST);$settings=self::normalize($settings,'miniprogram');
        foreach(self::MINI_FIELDS as $field=>$scheme){
            $required=($field==='udp_domain'||$field==='business_domain'&&array_key_exists($field,$settings))?[]:[($scheme!==''?$scheme.'://':'').$host];
            $settings[$field]=implode("\n",array_values(array_unique(array_merge($required,self::lines($settings[$field]??'')))));
        }
        return $settings;
    }

    public static function serverDomains(string $apiBaseUrl,array $settings=[]):array
    {
        $settings=self::miniProgram($apiBaseUrl,$settings);$required=[];
        foreach(['request_domain'=>'requestdomain','socket_domain'=>'wsrequestdomain','upload_file_domain'=>'uploaddomain','download_file_domain'=>'downloaddomain','udp_domain'=>'udpdomain'] as $field=>$key){$domains=self::lines($settings[$field]);if($domains)$required[$key]=$domains;}
        return $required;
    }

    public static function lines(string $value):array{return preg_split('/\s+/u',trim($value),-1,PREG_SPLIT_NO_EMPTY);}
}
