<?php
namespace app\common\service\power;

/** Public source labels follow the upstream channel identity, never model branding. */
final class MarketModelSource
{
    public static function channel(string $code): array
    {
        $code = trim($code);
        // `official` is the upstream catalog's reserved official-channel code.
        // A missing/default route does not prove a direct official connection.
        $source = $code === '' ? 'unknown' : (strtolower($code) === 'official' ? 'official' : 'third_party');
        return self::labels($source);
    }

    private static function labels(string $source): array
    {
        return ['channel_source'=>$source,'channel_source_label'=>['official'=>'官方渠道','third_party'=>'三方渠道','unknown'=>'其他渠道'][$source]];
    }

    public static function product(array $product): array
    {
        $snapshot = $product['source_payload'] ?? [];
        if (is_string($snapshot)) $snapshot = json_decode($snapshot, true) ?: [];
        $metadata = $snapshot['market_metadata'] ?? [];
        $record = ($product['resource_type'] ?? 'model') === 'app_api'
            ? ($metadata['upstream_api_metadata'] ?? []) : ($metadata['upstream_metadata'] ?? []);
        $fields = $metadata['catalog_badges'] ?? MarketCatalogBadges::fields($record);
        $tags = $fields['marketing_tags'] ?? [];
        if (!empty($fields['new_until']) && $fields['new_until'] <= time()) {
            $tags = array_values(array_diff($tags, ['new']));
        }
        // Recommendation belongs to this marketplace, independently of upstream badges.
        $tags = array_values(array_diff($tags, ['recommended']));
        if (!empty($product['is_recommended'])) array_unshift($tags, 'recommended');
        $source = $fields['channel_source'] ?? '';
        $labels = in_array($source, ['official','third_party','unknown'], true)
            ? self::labels($source) : self::channel((string)($product['upstream_channel_code'] ?? ''));
        return $labels + ['marketing_tags' => $tags];
    }

    /** Keep the existing order within the same promotion and channel tier. */
    public static function officialFirst(array $rows): array
    {
        $groups = array_fill(0, 8, []);
        foreach ($rows as $row) {
            $metadata = isset($row['channel_source'], $row['marketing_tags']) ? $row : $row + self::product($row);
            $tags = $metadata['marketing_tags'] ?? [];
            $priority = 3;
            foreach (['recommended', 'new', 'special_offer'] as $index => $tag) {
                if (in_array($tag, $tags, true)) {
                    $priority = $index;
                    break;
                }
            }
            $groups[2 * $priority + ($metadata['channel_source'] === 'official' ? 0 : 1)][] = $row;
        }
        return array_merge(...$groups);
    }

    /** Rank the full catalog before pagination, including pre-badge snapshots. */
    public static function officialFirstOrder(): string
    {
        $metadata = "JSON_EXTRACT(IF(JSON_VALID(source_payload), source_payload, '{}'), '$.market_metadata')";
        $badges = "COALESCE(JSON_EXTRACT($metadata, '$.catalog_badges'), JSON_EXTRACT($metadata, '$.upstream_metadata'))";
        $tags = "JSON_EXTRACT($badges, '$.marketing_tags')";
        $until = "COALESCE(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($badges, '$.new_until')), 'null') AS UNSIGNED), 0)";
        $channel = "JSON_UNQUOTE(JSON_EXTRACT($badges, '$.channel_source'))";
        return "CASE WHEN is_recommended = 1 THEN 0
            WHEN JSON_CONTAINS($tags, JSON_QUOTE('new')) AND ($until = 0 OR $until > UNIX_TIMESTAMP()) THEN 1
            WHEN JSON_CONTAINS($tags, JSON_QUOTE('special_offer')) THEN 2 ELSE 3 END,
            CASE WHEN COALESCE($channel, LOWER(TRIM(upstream_channel_code))) = 'official' THEN 0 ELSE 1 END";
    }

    public static function task(array $task): array
    {
        $snapshot = $task['pricing_snapshot'] ?? [];
        if (!is_array($snapshot)) $snapshot = json_decode((string)$snapshot,true) ?: [];
        // Existing tasks retain their immutable submit-time channel even after
        // a catalog rename, removal or a route change.
        if (!array_key_exists('channel_code',$snapshot) && (int)($task['tenant_id']??0)>0) {
            $query = \think\facade\Db::name('ai_consumption_log')->where('tenant_id',(int)$task['tenant_id']);
            if ((int)($task['consumption_id']??0)>0) $query->where('id',(int)$task['consumption_id']);
            elseif ((int)($task['app_task_id']??0)>0) $query->where('app_task_id',(int)$task['app_task_id']);
            else return self::channel('');
            $raw = $query->value('price_snapshot');
            $snapshot = is_array($raw) ? $raw : (json_decode((string)$raw,true) ?: []);
        }
        return self::channel((string)($snapshot['channel_code']??''));
    }
}
