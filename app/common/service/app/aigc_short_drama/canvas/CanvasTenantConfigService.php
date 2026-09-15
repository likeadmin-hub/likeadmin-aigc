<?php

namespace app\common\service\app\aigc_short_drama\canvas;

use think\facade\Db;

/**
 * Tenant-owned settings for the short-drama canvas only.
 *
 * These values intentionally live under aigc_short_drama's configuration row:
 * they do not read, inherit, or write the standalone canvas application's settings.
 */
final class CanvasTenantConfigService
{
    private const KEY = 'short_drama_canvas';

    public static function detail(int $tenantId): array
    {
        if ($tenantId <= 0) return self::defaults();
        $row = Db::name('aigc_short_drama_config')->where('tenant_id', $tenantId)->find();
        $json = self::decode((string)($row['config_json'] ?? ''));
        return self::normalize((array)($json[self::KEY] ?? []));
    }

    /** Model and billing settings always come from the short-drama resource state. */
    public static function save(int $tenantId, array $params): array
    {
        if ($tenantId <= 0) CanvasPolicy::fail('INVALID_TENANT', '租户配置无效');
        return Db::transaction(function () use ($tenantId, $params) {
            $row = Db::name('aigc_short_drama_config')->where('tenant_id', $tenantId)->lock(true)->find();
            $json = self::decode((string)($row['config_json'] ?? ''));
            $current = self::normalize((array)($json[self::KEY] ?? []));
            $json[self::KEY] = self::normalize([
                // There is no separate "open canvas" entitlement. The canvas
                // is part of short drama, so tenants with that app stay enabled.
                'enabled' => true,
                'read_only' => array_key_exists('read_only', $params) ? $params['read_only'] : $current['read_only'],
            ]);
            $data = ['config_json' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time()];
            if ($row) Db::name('aigc_short_drama_config')->where('id', $row['id'])->update($data);
            else Db::name('aigc_short_drama_config')->insert($data + ['tenant_id' => $tenantId, 'status' => 1, 'create_time' => time()]);
            return $json[self::KEY];
        });
    }

    private static function defaults(): array
    {
        return [
            // Canvas belongs to the short-drama application. Any tenant that can
            // use short drama gets the canvas entry unless an admin explicitly
            // switches this short-drama-only setting off.
            'enabled' => true,
            'read_only' => false,
            'execution_ready' => (bool)config('short_drama_canvas.execution_ready', false),
            'message' => '画布使用短剧租户已启用的模型、任务与算力市场规则。',
        ];
    }

    private static function normalize(array $value): array
    {
        $default = self::defaults();
        $enabled = true;
        $readOnly = filter_var($value['read_only'] ?? $default['read_only'], FILTER_VALIDATE_BOOLEAN);
        return array_replace($default, ['enabled' => $enabled, 'read_only' => $readOnly]);
    }

    private static function decode(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }
}
