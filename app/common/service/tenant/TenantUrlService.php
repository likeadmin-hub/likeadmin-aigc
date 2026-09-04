<?php

namespace app\common\service\tenant;

use think\facade\Config;
use app\common\service\tenant\TenantDomainAliasService;

class TenantUrlService
{
    public const ACCESS_SUBDOMAIN = 'subdomain';
    public const ACCESS_ID = 'id';
    public const ACCESS_ALIAS = 'alias';
    public const ACCESS_MODES = [
        self::ACCESS_SUBDOMAIN,
        self::ACCESS_ID,
        self::ACCESS_ALIAS,
    ];

    public static function links(array $tenant, ?string $rootDomain = null): array
    {
        $scheme = self::scheme();
        $rootDomain = $rootDomain ?: self::tenantRootDomain(request()->domain());
        $rootHost = self::normalizeHost((string)Config::get('project.http_host')) ?: $rootDomain;
        $id = (int)$tenant['id'];
        $sn = (string)$tenant['sn'];

        $subdomainBase = $scheme . '://' . $sn . '.' . $rootDomain;
        $aliasEnabled = (int)($tenant['domain_alias_enable'] ?? 1) === 0;
        $primaryAlias = TenantDomainAliasService::getPrimaryAlias($tenant);
        $aliasBase = $aliasEnabled && $primaryAlias !== '' ? $scheme . '://' . trim($primaryAlias, '/') : '';
        $idBase = $scheme . '://' . trim($rootHost, '/');

        $links = [
            'subdomain' => self::terminalLinks($subdomainBase),
            'alias' => $aliasBase ? self::terminalLinks($aliasBase) : null,
            'id_query' => [
                'admin' => $idBase . '/admin/?tenant_id=' . $id,
                'pc' => $idBase . '/?tenant_id=' . $id,
                'mobile' => $idBase . '/mobile/?tenant_id=' . $id,
            ],
            'id_path' => [
                'admin' => $idBase . '/t/' . $id . '/admin/',
                'pc' => $idBase . '/t/' . $id . '/',
                'mobile' => $idBase . '/t/' . $id . '/mobile/',
            ],
        ];
        $accessMode = self::normalizeAccessMode($tenant['access_mode'] ?? '');
        $links['current'] = self::currentLinks($links, $accessMode);

        // Keep the platform-root ID links above for compatibility, but also
        // expose ID links on the effective tenant host. These are the links
        // shown to platform operators after changing a tenant alias.
        $tenantBase = $subdomainBase;
        if ($accessMode === self::ACCESS_ALIAS && $aliasBase !== '') {
            $tenantBase = $aliasBase;
        } elseif ($accessMode === self::ACCESS_ID) {
            $tenantBase = $idBase;
        }
        $links['tenant_id_query'] = [
            'admin' => $tenantBase . '/admin/?tenant_id=' . $id,
            'pc' => $tenantBase . '/?tenant_id=' . $id,
            'mobile' => $tenantBase . '/mobile/?tenant_id=' . $id,
        ];
        $links['tenant_id_path'] = [
            'admin' => $tenantBase . '/t/' . $id . '/admin/',
            'pc' => $tenantBase . '/t/' . $id . '/',
            'mobile' => $tenantBase . '/t/' . $id . '/mobile/',
        ];
        return $links;
    }

    public static function attach(array $tenant, ?string $rootDomain = null): array
    {
        $tenant['access_mode'] = self::normalizeAccessMode($tenant['access_mode'] ?? '');
        $tenant['links'] = self::links($tenant, $rootDomain);
        $tenant['default_domain'] = $tenant['links']['subdomain']['admin'];
        $tenant['domain'] = $tenant['links']['current']['admin'] ?? $tenant['default_domain'];
        // This convenience field follows the effective tenant host. Keep the
        // old platform-root value available for integrations that explicitly
        // need the platform ID entry.
        $tenant['tenant_id_domain'] = $tenant['links']['tenant_id_query']['admin'];
        $tenant['platform_tenant_id_domain'] = $tenant['links']['id_query']['admin'];
        $tenant['primary_domain_alias'] = $tenant['links']['alias']['admin'] ?? '';
        return $tenant;
    }

    public static function normalizeAccessMode(string $mode): string
    {
        return in_array($mode, self::ACCESS_MODES, true) ? $mode : self::ACCESS_SUBDOMAIN;
    }

    public static function tenantRootDomain(?string $url = null): string
    {
        $configured = self::normalizeHost((string)Config::get('project.http_host'));
        if ($configured !== '') {
            return $configured;
        }

        $host = self::normalizeHost($url ?: request()->domain());
        if ($host === '') {
            return '';
        }

        $parts = explode('.', $host);
        if (count($parts) > 3) {
            array_shift($parts);
            return implode('.', $parts);
        }
        return $host;
    }

    public static function tenantSnFromHost(string $host): string
    {
        $host = self::normalizeHost($host);
        if ((string)Config::get('project.http_host') === '') {
            $parts = explode('.', $host);
            return count($parts) >= 3 ? (string)$parts[0] : '';
        }
        $rootDomain = self::tenantRootDomain($host);
        if ($host === '' || $rootDomain === '' || $host === $rootDomain) {
            return '';
        }
        $suffix = '.' . $rootDomain;
        if (!str_ends_with($host, $suffix)) {
            return '';
        }
        $prefix = substr($host, 0, -strlen($suffix));
        return $prefix !== '' && !str_contains($prefix, '.') ? $prefix : '';
    }

    public static function normalizeHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = strtolower(trim($host));
        $host = preg_replace('/^https?:\/\//', '', $host);
        $host = trim((string)$host, '/');
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }
        return $host;
    }

    /**
     * Return the platform hosts configured for platform administration.
     * A tenant alias must not shadow these hosts or their subdomains because
     * the platform middleware intentionally treats the whole host tree as a
     * platform entry surface.
     */
    public static function platformHosts(?string $requestHost = null): array
    {
        $configured = (string)Config::get('project.http_host');
        $hosts = preg_split('/\s*,\s*/', trim($configured)) ?: [];
        if ($requestHost !== null && trim($requestHost) !== '') {
            $hosts[] = $requestHost;
        }
        $hosts = array_map(static fn($host) => self::normalizeHost((string)$host), $hosts);
        return array_values(array_unique(array_filter($hosts)));
    }

    public static function isPlatformHost(string $host, ?string $requestHost = null): bool
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return false;
        }
        foreach (self::platformHosts($requestHost) as $platformHost) {
            if ($host === $platformHost || str_ends_with($host, '.' . $platformHost)) {
                return true;
            }
        }
        return false;
    }

    private static function terminalLinks(string $base): array
    {
        return [
            'admin' => $base . '/admin/',
            'pc' => $base . '/',
            'mobile' => $base . '/mobile/',
        ];
    }

    private static function currentLinks(array $links, string $accessMode): array
    {
        if ($accessMode === self::ACCESS_ID) {
            return $links['id_query'];
        }
        if ($accessMode === self::ACCESS_ALIAS && !empty($links['alias'])) {
            return $links['alias'];
        }
        return $links['subdomain'];
    }

    private static function scheme(): string
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    }
}
