<?php
declare(strict_types=1);
namespace app\common\service\distribution;

use app\common\service\tenant\TenantUrlService;
use app\common\service\tenant\TenantDomainAliasService;
use think\facade\Config;
use think\facade\Db;
use RuntimeException;

/** Builds the owned H5 destination; the PC client encodes it with its existing QR library. */
final class PosterH5CodeService
{
    public static function code(int $tenantId, int $userId, array $profile): array
    {
        $tenant=Db::name('tenant')->where('id',$tenantId)->whereNull('delete_time')->find();
        if (!$tenant) throw new RuntimeException('租户不存在');
        $invite=(string)($profile['invite_code']??'');
        if (!preg_match('/^[A-Z0-9]{4,32}$/D',$invite)) throw new RuntimeException('推广码无效，请重新打开推广中心');

        // TenantUrlService may otherwise fall back to request Host. A shareable QR must
        // use a configured platform root or this tenant's enabled, persisted alias.
        $root=TenantUrlService::normalizeHost((string)Config::get('project.http_host'));
        $mode=TenantUrlService::normalizeAccessMode((string)($tenant['access_mode']??''));
        $alias=$mode===TenantUrlService::ACCESS_ALIAS&&(int)($tenant['domain_alias_enable']??1)===0
            ? TenantDomainAliasService::getPrimaryAlias($tenant) : '';
        if ($root===''&&$alias==='') throw new RuntimeException('请先配置平台域名或租户独立域名，再生成推广码');
        $links=TenantUrlService::links($tenant,$root?:$alias);
        $mobile=(string)($links['current']['mobile']??'');
        $parts=parse_url($mobile);
        if (!is_array($parts)||!in_array($parts['scheme']??'',['http','https'],true)
            ||empty($parts['host'])||!filter_var($mobile,FILTER_VALIDATE_URL)
            ||isset($parts['user'])||isset($parts['pass'])||($parts['path']??'')!=='/mobile/') {
            throw new RuntimeException('租户手机端域名配置无效，请联系管理员');
        }
        // Replace any existing query, including TenantUrlService's ID-mode query.
        $destination=explode('?',$mobile,2)[0].'?'.http_build_query(
            ['tenant_id'=>$tenantId,'invite_code'=>$invite],'','&',PHP_QUERY_RFC3986
        );
        return [
            'channel'=>'h5', 'destination'=>$destination,
            'cache_key'=>hash('sha256',$tenantId.'|'.$userId.'|h5|'.$destination.'|v1'),
            'profile'=>$profile,
        ];
    }
}
