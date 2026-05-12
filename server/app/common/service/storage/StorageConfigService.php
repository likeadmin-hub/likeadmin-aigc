<?php

namespace app\common\service\storage;

use app\common\enum\AdminTerminalEnum;
use app\common\model\Config;
use app\common\model\TenantConfig;
use app\common\model\tenant\Tenant;
use think\facade\Cache;

class StorageConfigService
{
    public static function getEffectiveConfig(?int $tenantId = null): array
    {
        $scope = self::effectiveScope($tenantId);
        $cacheKey = $scope === 'tenant' ? 'STORAGE_ENGINE_TENANT_' . $tenantId : 'STORAGE_ENGINE_PLATFORM';
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }
        $default = self::getEffectiveDefault($tenantId);
        $engine = $default === 'local' ? ['local' => []] : [$default => self::getConfig($scope, $tenantId, $default, [])];
        $config = ['scope' => $scope, 'default' => $default, 'engine' => $engine];
        Cache::set($cacheKey, $config);
        return $config;
    }

    public static function getEffectiveDefault(?int $tenantId = null): string
    {
        $scope = self::effectiveScope($tenantId);
        $cacheKey = $scope === 'tenant' ? 'STORAGE_DEFAULT_TENANT_' . $tenantId : 'STORAGE_DEFAULT_PLATFORM';
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }
        $default = (string)self::getConfig($scope, $tenantId, 'default', 'local');
        Cache::set($cacheKey, $default);
        return $default;
    }

    public static function getEffectiveDomain(?int $tenantId = null): string
    {
        $default = self::getEffectiveDefault($tenantId);
        if ($default === 'local') {
            return request()->domain();
        }
        $config = self::getConfig(self::effectiveScope($tenantId), $tenantId, $default, []);
        return $config['domain'] ?? '';
    }

    public static function getStoredFileConfig(?int $tenantId, ?string $scope, ?string $engine): array
    {
        $scope = in_array($scope, ['tenant', 'platform'], true) ? $scope : self::effectiveScope($tenantId);
        $engine = $engine ?: self::getEffectiveDefault($tenantId);
        if ($engine === 'local') {
            return ['scope' => $scope, 'default' => 'local', 'engine' => ['local' => []]];
        }
        $config = self::getConfig($scope, $tenantId, $engine, []);
        if (!$config) {
            return self::getEffectiveConfig($tenantId);
        }
        return ['scope' => $scope, 'default' => $engine, 'engine' => [$engine => $config]];
    }

    public static function isTenantStorageEnabled(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }
        $tenant = Tenant::where('id', $tenantId)->findOrEmpty();
        if ($tenant->isEmpty() || (int)($tenant['allow_custom_storage'] ?? 0) !== 1) {
            return false;
        }
        return (int)self::getConfig('tenant', $tenantId, 'enable', 0) === 1;
    }

    public static function currentTenantId(): ?int
    {
        return isset(request()->tenantId) ? (int)request()->tenantId : null;
    }

    public static function currentScope(): string
    {
        $tenantId = self::currentTenantId();
        return self::effectiveScope($tenantId);
    }

    public static function clearCache(?int $tenantId = null): void
    {
        Cache::delete('STORAGE_DEFAULT_PLATFORM');
        Cache::delete('STORAGE_ENGINE_PLATFORM');
        if ($tenantId) {
            Cache::delete('STORAGE_DEFAULT_TENANT_' . $tenantId);
            Cache::delete('STORAGE_ENGINE_TENANT_' . $tenantId);
        }
    }

    private static function effectiveScope(?int $tenantId): string
    {
        if ($tenantId && !AdminTerminalEnum::isPlatform() && self::isTenantStorageEnabled($tenantId)) {
            return 'tenant';
        }
        return 'platform';
    }

    private static function getConfig(string $scope, ?int $tenantId, string $name, mixed $default = null): mixed
    {
        $query = ['type' => 'storage', 'name' => $name];
        $model = $scope === 'tenant' ? new TenantConfig() : new Config();
        if ($scope === 'tenant') {
            $query['tenant_id'] = $tenantId;
        }
        $value = $model->where($query)->value('value');
        if ($value === null) {
            return $default;
        }
        $json = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $json : $value;
    }
}
