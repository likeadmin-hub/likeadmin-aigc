<?php

namespace app\common\service\ai;

use app\common\model\TenantConfig;

/**
 * Result URLs are kept at the supplier by default. Workflow assets can pass
 * force=true because downstream generation must not depend on expiring URLs.
 */
class AiTaskResultStorageService
{
    public const CONFIG_TYPE = 'ai_task';
    public const CONFIG_NAME = 'result_transfer_enabled';

    public static function transferEnabled(int $tenantId, bool $force = false): bool
    {
        if ($force) {
            return true;
        }
        $value = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::CONFIG_TYPE,
            'name' => self::CONFIG_NAME,
        ])->value('value');
        if ($value === null) {
            return false;
        }
        $decoded = json_decode((string)$value, true);
        return $decoded === true || $decoded === 1 || $value === '1' || $value === 1;
    }

    public static function setTransferEnabled(int $tenantId, bool $enabled): void
    {
        $now = time();
        $row = TenantConfig::where([
            'tenant_id' => $tenantId,
            'type' => self::CONFIG_TYPE,
            'name' => self::CONFIG_NAME,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            TenantConfig::create([
                'tenant_id' => $tenantId,
                'type' => self::CONFIG_TYPE,
                'name' => self::CONFIG_NAME,
                'value' => $enabled ? '1' : '0',
                'create_time' => $now,
                'update_time' => $now,
            ]);
            return;
        }
        $row->save(['value' => $enabled ? '1' : '0', 'update_time' => $now]);
    }
}
