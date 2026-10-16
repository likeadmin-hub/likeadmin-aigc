<?php

namespace app\common\service\wechat;

use app\common\model\tenant\Tenant;
use app\common\service\tenant\TenantUrlService;

/** Server-managed tenant bindings are the source of mini-program API domains. */
class MiniProgramDomainService
{
    public static function host(int $tenantId): string
    {
        if ($tenantId <= 0) return '';
        $tenant = Tenant::where('id', $tenantId)->findOrEmpty();
        if ($tenant->isEmpty()) return '';
        $links = TenantUrlService::links($tenant->toArray());
        return TenantUrlService::normalizeHost((string)(
            $links['alias']['pc'] ?? $links['subdomain']['pc'] ?? ''
        ));
    }
}
