<?php

namespace app\common\service\app;

use think\facade\Db;

/** Read switches only, without invoking business config builders or creating defaults. */
final class AppAvailabilityConfigService
{
    // These are the owning service config tables. Canvas/copywriting use display only.
    private const STATUS_APPS = [
        'aigc_image', 'aigc_video', 'aigc_digital_human', 'aigc_llm',
        'aigc_hairstyle', 'aigc_fitting', 'aigc_product_image', 'aigc_style_transfer',
        'aigc_photo_restore', 'aigc_image_translate', 'aigc_one_click_cleanup',
        'aigc_product_multi_angle', 'aigc_product_promo_video', 'aigc_action_transfer',
        'aigc_outpaint', 'aigc_product_suite', 'aigc_local_redraw', 'aigc_person_replacement',
        'aigc_short_drama', 'aigc_background_removal', 'aigc_fashion_lookbook', 'aigc_geo',
        'aigc_model_wear', 'aigc_music', 'aigc_music_cover', 'aigc_watermark_removal',
        'aigc_pic_lipsync', 'image_human', 'smart_clip',
    ];

    public static function filterAvailable(int $tenantId, array $appCodes): array
    {
        $appCodes = array_values(array_unique($appCodes));
        if (!$appCodes) return [];
        $closed = AppDisplayConfigService::closedAppCodes($tenantId, $appCodes);
        $sources = self::sources();
        $queries = [];
        $bindings = [];
        foreach (array_intersect($appCodes, array_keys($sources)) as $appCode) {
            [$table, $field] = $sources[$appCode];
            // Identifiers come only from our fixed inventory and the configured prefix.
            $table = '`'.str_replace('`', '``', $table).'`';
            // Legacy app switches can be VARCHAR with different collations.
            // Their existing semantics are numeric; normalize before UNION.
            $queries[] = "SELECT ? AS app_code, CAST(`{$field}` AS SIGNED) AS switch_value FROM {$table} WHERE tenant_id IN (0, ?)";
            array_push($bindings, $appCode, $tenantId);
        }
        if ($queries) {
            foreach (Db::query(implode(' UNION ALL ', $queries), $bindings) as $row) {
                $value = $row['switch_value'];
                if ((int)$value !== 1) $closed[] = $row['app_code'];
            }
        }
        return array_values(array_diff($appCodes, $closed));
    }

    /** Discover installed sources each time: workers may outlive an optional app install. */
    private static function sources(): array
    {
        $connection = (array)config('database.connections.mysql');
        $prefix = (string)($connection['prefix'] ?? '');
        $sources = [];
        foreach (self::STATUS_APPS as $code) $sources[$code] = [$prefix.$code.'_config', 'status'];
        $tables = array_column($sources, 0);
        $columns = Db::query('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tables), '?')).') AND COLUMN_NAME IN (\'status\',\'config_json\')', $tables);
        $present = [];
        foreach ($columns as $column) $present[$column['table_name'].':'.$column['column_name']] = true;
        // Older installations may not contain an optional app's table yet.
        return array_filter($sources, static fn(array $source): bool => isset($present[implode(':', $source)]));
    }
}
