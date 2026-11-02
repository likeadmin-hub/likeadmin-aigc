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
        return ['channel_source'=>$source,'channel_source_label'=>['official'=>'官方渠道','third_party'=>'三方渠道','unknown'=>'渠道未标注'][$source]];
    }

    public static function product(array $product): array
    {
        return self::channel((string)($product['upstream_channel_code'] ?? ''));
    }

    /** Stable partition: all existing price, rank and SKU ordering survives within each group. */
    public static function officialFirst(array $rows): array
    {
        $official = []; $other = [];
        foreach ($rows as $row) {
            $source = $row['channel_source'] ?? self::product($row)['channel_source'];
            if ($source === 'official') $official[] = $row;
            else $other[] = $row;
        }
        return array_merge($official, $other);
    }

    /** SQL priority must precede LIMIT so official entries cannot hide on later pages. */
    public static function officialFirstOrder(): string
    {
        return "CASE WHEN LOWER(TRIM(upstream_channel_code)) = 'official' THEN 0 ELSE 1 END";
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
