<?php

namespace app\common\service\tenant;

use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantDomainAlias;
use Exception;
use think\facade\Db;

class TenantDomainAliasService
{
    public static function normalizeAliasList(array $aliases, string $legacyAlias = ''): array
    {
        $normalized = [];
        foreach ($aliases as $index => $alias) {
            $domain = '';
            $isPrimary = 0;
            $status = 1;
            if (is_array($alias)) {
                $domain = TenantUrlService::normalizeHost((string)($alias['domain'] ?? ''));
                $isPrimary = (int)($alias['is_primary'] ?? 0) === 1 ? 1 : 0;
                $status = (int)($alias['status'] ?? 1) === 0 ? 0 : 1;
            } else {
                $domain = TenantUrlService::normalizeHost((string)$alias);
            }
            if ($domain === '') {
                continue;
            }
            if (!isset($normalized[$domain])) {
                $normalized[$domain] = [
                    'domain' => $domain,
                    'is_primary' => $isPrimary,
                    'status' => $status,
                    'sort' => $index,
                ];
                continue;
            }
            if ($isPrimary === 1) {
                $normalized[$domain]['is_primary'] = 1;
            }
            if ($status === 1) {
                $normalized[$domain]['status'] = 1;
            }
        }

        if ($normalized === []) {
            $legacy = TenantUrlService::normalizeHost($legacyAlias);
            if ($legacy !== '') {
                $normalized[$legacy] = [
                    'domain' => $legacy,
                    'is_primary' => 1,
                    'status' => 1,
                    'sort' => 0,
                ];
            }
        }

        $primary = array_values(array_filter($normalized, fn($item) => (int)$item['is_primary'] === 1));
        if (count($primary) === 0 && $normalized !== []) {
            $first = array_key_first($normalized);
            $normalized[$first]['is_primary'] = 1;
            $normalized[$first]['status'] = 1;
        } elseif (count($primary) > 1) {
            $keep = $primary[0]['domain'];
            foreach ($normalized as $domain => $alias) {
                $normalized[$domain]['is_primary'] = $domain === $keep ? 1 : 0;
                if ($domain === $keep) {
                    $normalized[$domain]['status'] = 1;
                }
            }
        }

        uasort($normalized, function (array $a, array $b) {
            if ((int)$a['is_primary'] !== (int)$b['is_primary']) {
                return (int)$b['is_primary'] <=> (int)$a['is_primary'];
            }
            return (int)$a['sort'] <=> (int)$b['sort'];
        });

        return array_map(function (array $alias) {
            unset($alias['sort']);
            return $alias;
        }, array_values($normalized));
    }

    public static function validateAliases(array $aliases, int $tenantId = 0, bool $requireActive = false): void
    {
        $activeCount = 0;
        $primaryCount = 0;
        foreach ($aliases as $alias) {
            $domain = TenantUrlService::normalizeHost((string)($alias['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $requestHost = '';
            try {
                $requestHost = (string)request()->domain();
            } catch (\Throwable) {
                // Validation can also run from a CLI/import context.
            }
            if (TenantUrlService::isPlatformHost($domain, $requestHost)) {
                throw new Exception('租户域名不能与平台域名一致或使用平台域名的子域名：' . $domain);
            }
            if ((int)($alias['status'] ?? 1) === 1) {
                $activeCount++;
            }
            if ((int)($alias['is_primary'] ?? 0) === 1) {
                $primaryCount++;
            }

            $aliasRow = Db::name('tenant_domain_alias')->where('domain', $domain)->find();
            if (!empty($aliasRow) && !self::canReuseAliasRow($aliasRow, $tenantId)) {
                throw new Exception('租户别名已存在：' . $domain);
            }

            $tenantQuery = Tenant::where('domain_alias', $domain);
            if ($tenantId > 0) {
                $tenantQuery->where('id', '<>', $tenantId);
            }
            if (!$tenantQuery->findOrEmpty()->isEmpty()) {
                throw new Exception('租户别名已存在：' . $domain);
            }
        }

        if ($primaryCount > 1) {
            throw new Exception('只能设置一个主域名别名');
        }
        if ($requireActive && $activeCount === 0) {
            throw new Exception('启用域名别名时，请至少设置一个启用的域名别名');
        }
    }

    public static function syncTenantAliases(int $tenantId, array $aliases, string $legacyAlias = '', bool $requireActive = false): string
    {
        $aliases = self::normalizeAliasList($aliases, $legacyAlias);
        self::validateAliases($aliases, $tenantId, $requireActive);

        // Rebuild the tenant's alias set from the submitted values. A tenant
        // domain edit must not leave an old alias (including a soft-deleted
        // row) available to the host resolver or to a later form submission.
        Db::name('tenant_domain_alias')->where('tenant_id', $tenantId)->delete();

        $now = time();
        $primary = '';
        foreach ($aliases as $alias) {
            $domain = (string)$alias['domain'];
            if ((int)$alias['is_primary'] === 1) {
                $primary = $domain;
            }

            $row = Db::name('tenant_domain_alias')->where('domain', $domain)->find();
            if (!empty($row) && !self::canReuseAliasRow($row, $tenantId)) {
                throw new Exception('租户别名已存在：' . $domain);
            }

            $data = [
                'tenant_id' => $tenantId,
                'domain' => $domain,
                'is_primary' => (int)$alias['is_primary'] === 1 ? 1 : 0,
                'status' => (int)($alias['status'] ?? 1) === 0 ? 0 : 1,
                'update_time' => $now,
                'delete_time' => null,
            ];
            if (!empty($row)) {
                Db::name('tenant_domain_alias')->where('id', (int)$row['id'])->update($data);
                continue;
            }
            $data['create_time'] = $now;
            Db::name('tenant_domain_alias')->insert($data);
        }

        return $primary !== '' ? $primary : (string)($aliases[0]['domain'] ?? '');
    }

    public static function getAliasesByTenantId(int $tenantId): array
    {
        try {
            return TenantDomainAlias::withoutGlobalScope()
                ->where('tenant_id', $tenantId)
                ->whereNull('delete_time')
                ->order(['is_primary' => 'desc', 'id' => 'asc'])
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function findTenantByDomain(string $domain): Tenant
    {
        $domain = TenantUrlService::normalizeHost($domain);
        if ($domain === '') {
            return (new Tenant())->newInstance();
        }
        try {
            $alias = TenantDomainAlias::withoutGlobalScope()
                ->where(['domain' => $domain, 'status' => 1])
                ->whereNull('delete_time')
                ->findOrEmpty();
            if (!$alias->isEmpty()) {
                $tenant = Tenant::where('id', (int)$alias['tenant_id'])->findOrEmpty();
                if (!$tenant->isEmpty()) {
                    return $tenant;
                }
            }
        } catch (\Throwable) {
        }
        return Tenant::where('domain_alias', $domain)->findOrEmpty();
    }

    private static function canReuseAliasRow(array $aliasRow, int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return empty($aliasRow);
        }
        if ((int)($aliasRow['tenant_id'] ?? 0) === $tenantId) {
            return true;
        }
        if (!empty($aliasRow['delete_time'])) {
            return true;
        }
        return Tenant::where('id', (int)($aliasRow['tenant_id'] ?? 0))->findOrEmpty()->isEmpty();
    }

    public static function getPrimaryAlias(array $tenant): string
    {
        $tenantId = (int)($tenant['id'] ?? 0);
        if ($tenantId > 0) {
            try {
                $alias = TenantDomainAlias::withoutGlobalScope()
                    ->where(['tenant_id' => $tenantId, 'is_primary' => 1, 'status' => 1])
                    ->whereNull('delete_time')
                    ->order('id', 'asc')
                    ->findOrEmpty();
                if (!$alias->isEmpty()) {
                    return TenantUrlService::normalizeHost((string)$alias['domain']);
                }
            } catch (\Throwable) {
            }
        }
        return TenantUrlService::normalizeHost((string)($tenant['domain_alias'] ?? ''));
    }
}
