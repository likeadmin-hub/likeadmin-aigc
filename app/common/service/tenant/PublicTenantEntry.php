<?php

namespace app\common\service\tenant;

use app\common\service\distribution\DistributionService;
use app\common\service\JsonService;
use Closure;
use think\facade\Db;
use RuntimeException;

/** A host-only routing preference for public PC/H5 pages. API authentication remains independent. */
final class PublicTenantEntry
{
    private const COOKIE = 'aigc_public_tenant';

    public static function hasContext($request): bool
    {
        return self::inviteCode($request) !== '' || (int)$request->cookie(self::COOKIE, 0) > 0;
    }

    public static function tenantId($request): int
    {
        if (!self::isPublicPage($request) && !self::isEntryRequest($request)) {
            return 0;
        }
        $code = self::inviteCode($request);
        if ($code !== '') {
            try {
                return DistributionService::tenantIdByInviteCode($code);
            } catch (RuntimeException $e) {
                return 0;
            }
        }
        return max(0, (int)$request->cookie(self::COOKIE, 0));
    }

    public static function respond($request, Closure $next)
    {
        $isEntry = self::isEntryRequest($request);
        $code = ($isEntry || self::isPublicPage($request)) ? self::inviteCode($request) : '';
        if ($code !== '' && !Db::name('distribution_relation')->where([
            'tenant_id' => (int)$request->tenantId, 'invite_code' => strtoupper($code),
        ])->find()) {
            return $isEntry ? JsonService::fail('推广码不属于当前租户') : $next($request);
        }
        if (self::isPublicPage($request) && ($target = self::cleanAddress($request)) !== '') {
            $response = redirect($target)->header(['Cache-Control' => 'no-store']);
        } else {
            $response = $next($request);
        }
        if (($request->tenantResolveMode === 'param_id' || $request->tenantResolveMode === 'path_id')
            && (self::isPublicPage($request) || $isEntry)) {
            $response->header(['Cache-Control' => 'private, no-store']);
            if ((int)$request->cookie(self::COOKIE, 0) !== (int)$request->tenantId) {
                $response->cookie(self::COOKIE, (string)$request->tenantId, [
                    'path' => '/', 'domain' => '', 'secure' => $request->isSsl(), 'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
        }
        return $response;
    }

    private static function cleanAddress($request): string
    {
        $uri = (string)$request->server('REQUEST_URI', '');
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }
        parse_str($parts['query'] ?? '', $query);
        $cleanPath = preg_replace('#^/t/\d+(?=/|$)#D', '', $path) ?: '/';
        if ($cleanPath === $path && !array_key_exists('tenant_id', $query) && !array_key_exists('tenantId', $query)) {
            return '';
        }
        if (str_starts_with($cleanPath, '//') || str_contains($cleanPath, '\\') || preg_match('/%2f|%5c/i', $cleanPath)) return '';
        unset($query['tenant_id'], $query['tenantId']);
        return $cleanPath . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private static function isPublicPage($request): bool
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $path = trim($request->pathinfo(), '/');
        $path = preg_replace('#^t/\d+(?:/|$)#', '', $path);
        return $path === '' || preg_match('#^(?:pc|mobile|ai|app|account|user|page|policy|products|scenes|cases|pricing|official|join-opc|docs|login|register)(?:/|$)#', $path);
    }

    private static function isEntryRequest($request): bool
    {
        return $request->method() === 'GET' && trim($request->pathinfo(), '/') === 'api/distribution/entry';
    }

    private static function inviteCode($request): string
    {
        $value = $request->get('invite_code', $request->get('inviteCode', ''));
        return is_string($value) && preg_match('/^[A-Za-z0-9]{4,32}$/D', $value) ? $value : '';
    }
}
