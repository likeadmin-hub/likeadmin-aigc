<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\open_platform\OpenStore;

/** Rebuild local managed URLs in the admitted request's origin, never the CLI host. */
final class AgentRequestOrigin
{
    public static function normalize(string $origin): string
    {
        $p=parse_url($origin);
        if(!$p||!in_array($p['scheme']??'',['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)||preg_match('~[\s/\\\\?#@]~',$p['host']))return '';
        if(isset($p['port'])&&($p['port']<1||$p['port']>65535))return '';
        return $p['scheme'].'://'.strtolower($p['host']).(isset($p['port'])?':'.$p['port']:'');
    }
    public static function fromRun(array $run): string
    {
        $origin=self::normalize((string)(AgentStore::json($run['preferences'])['_request_origin']??''));
        if($origin!=='')return $origin;
        // Legacy local attachments already contain a server-authorized HTTP snapshot.
        // Cloud signatures and arbitrary historic URLs are never used as an origin.
        foreach(AgentStore::json($run['attachments']??[]) as$asset){
            if(!is_array($asset)||!preg_match('/^of_[a-f0-9]{32}$/D',(string)($asset['file_id']??'')))continue;
            $file=OpenStore::owner('file',(int)$run['tenant_id'],(int)$run['user_id'])->where('file_id',$asset['file_id'])->find();
            $url=parse_url((string)($asset['url']??''));
            if(!$file||$file['storage_engine']!=='local'||!$url||ltrim((string)($url['path']??''),'/')!==ltrim((string)$file['uri'],'/'))continue;
            $origin=self::normalize(($url['scheme']??'').'://'.($url['host']??'').(isset($url['port'])?':'.$url['port']:''));
            if($origin!=='')return $origin;
        }
        return '';
    }
    public static function server(array $server,string $origin): array
    {
        $origin=self::normalize($origin);if($origin==='')return $server;
        $p=parse_url($origin);$host=$p['host'].(isset($p['port'])?':'.$p['port']:'');
        return array_replace($server,['HTTP_HOST'=>$host,'HTTP_X_FORWARDED_HOST'=>$host,'REQUEST_SCHEME'=>$p['scheme'],'HTTP_X_FORWARDED_PROTO'=>$p['scheme'],'HTTPS'=>$p['scheme']==='https'?'on':'off','SERVER_PORT'=>$p['port']??($p['scheme']==='https'?443:80)]);
    }
}
