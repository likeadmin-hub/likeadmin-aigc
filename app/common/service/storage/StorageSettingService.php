<?php

namespace app\common\service\storage;

use app\common\model\Config;
use app\common\model\TenantConfig;

class StorageSettingService
{
    public static function set(string $scope, ?int $tenantId, string $name, mixed $value): void
    {
        $payload = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        $query = ['type' => 'storage', 'name' => $name];
        $data = ['type' => 'storage', 'name' => $name, 'value' => $payload];
        $model = $scope === 'tenant' ? new TenantConfig() : new Config();
        if ($scope === 'tenant') {
            $query['tenant_id'] = $tenantId;
            $data['tenant_id'] = $tenantId;
        }
        $row = $model->where($query)->findOrEmpty();
        if ($row->isEmpty()) {
            $model->create($data);
        } else {
            $row->save(['value' => $payload]);
        }
        StorageConfigService::clearCache($tenantId);
    }
}

