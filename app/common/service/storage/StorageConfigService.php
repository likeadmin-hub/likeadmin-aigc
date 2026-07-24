<?php

namespace app\common\service\storage;

use app\common\enum\AdminTerminalEnum;
use app\common\model\Config;
use app\common\model\TenantConfig;
use app\common\model\tenant\Tenant;
use think\facade\Cache;

class StorageConfigService
{
    private const ENGINE_LABELS = [
        'local' => '本地存储',
        'qiniu' => '七牛云存储',
        'aliyun' => '阿里云OSS',
        'qcloud' => '腾讯云COS',
    ];

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
        return self::getStorageDomain(self::getEffectiveConfig($tenantId));
    }

    /**
     * Returns the storage engines that can actually be used in the tenant's
     * effective storage scope. Credentials stay server-side.
     */
    public static function availableStorageOptions(int $tenantId): array
    {
        $options = [];
        foreach (self::ENGINE_LABELS as $engine => $name) {
            try {
                self::getConfiguredStorageConfig($tenantId, $engine);
                $options[] = ['engine' => $engine, 'name' => $name];
            } catch (\Throwable) {
                continue;
            }
        }
        return $options;
    }

    /**
     * Resolves one explicitly selected storage engine in the same scope used
     * by normal tenant uploads. This is intentionally not returned to clients.
     */
    public static function getConfiguredStorageConfig(int $tenantId, string $engine): array
    {
        $engine = strtolower(trim($engine));
        if (!array_key_exists($engine, self::ENGINE_LABELS)) {
            throw new \InvalidArgumentException('不支持的存储方式');
        }
        $scope = self::effectiveScope($tenantId);
        if ($engine === 'local') {
            if ($scope === 'tenant' && !self::tenantAllowsLocalStorage($tenantId)) {
                throw new \InvalidArgumentException('当前租户未允许使用本地存储');
            }
            return ['scope' => $scope, 'default' => 'local', 'engine' => ['local' => []]];
        }

        $config = self::getConfig($scope, $tenantId, $engine, []);
        if (!is_array($config) || !self::configuredEngine($engine, $config)) {
            throw new \InvalidArgumentException('所选存储方式尚未配置完成');
        }
        return ['scope' => $scope, 'default' => $engine, 'engine' => [$engine => $config]];
    }

    public static function getStorageDomain(array $config): string
    {
        $engine = (string)($config['default'] ?? 'local');
        if ($engine === 'local') {
            return request()->domain();
        }
        return (string)($config['engine'][$engine]['domain'] ?? '');
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
        $enabled = (int)self::getConfig('tenant', $tenantId, 'enable', 0) === 1;
        if (!$enabled) {
            return false;
        }
        $default = (string)self::getConfig('tenant', $tenantId, 'default', 'local');
        if ($default === 'local' && (int)($tenant['allow_local_storage'] ?? 1) !== 1) {
            return false;
        }
        return true;
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

    private static function tenantAllowsLocalStorage(int $tenantId): bool
    {
        return (int)Tenant::where('id', $tenantId)->value('allow_local_storage') === 1;
    }

    private static function configuredEngine(string $engine, array $config): bool
    {
        $required = ['bucket', 'access_key', 'secret_key', 'domain'];
        if ($engine === 'qcloud') {
            $required[] = 'region';
        }
        foreach ($required as $key) {
            if (trim((string)($config[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
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
