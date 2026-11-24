<?php
namespace app\common\service\power;

/** Model discovery and bulk shelf operations consume the same normalized metadata. */
final class MarketModelFilter
{
    public static function isBulkRequest(array $params): bool
    {
        $all = $params['all'] ?? false;
        if (in_array($all, [false, 0, '0'], true)) return false;
        if (!in_array($all, [true, 1, '1'], true)) throw new \InvalidArgumentException('批量操作范围无效');
        if (!in_array($params['sale_status'] ?? null, [0, 1, '0', '1'], true)
            || !in_array($params['status'] ?? '', ['', 0, 1, '0', '1'], true)
            || !in_array($params['api_status'] ?? '', ['', 0, 1, '0', '1'], true)
            || !in_array($params['model_type'] ?? '', ['', 'text', 'image', 'video', 'other'], true)
            || !in_array($params['channel_source'] ?? '', ['', 'official', 'third_party', 'unknown'], true)
            || !is_string($params['keyword'] ?? '')
            || !is_string($params['other_category_code'] ?? '')
            || !is_array($params['marketing_tags'] ?? [])) {
            throw new \InvalidArgumentException('模型筛选条件无效');
        }
        foreach ($params['marketing_tags'] ?? [] as $tag) {
            if (!in_array($tag, ['recommended','new','special_offer'], true)) throw new \InvalidArgumentException('模型营销标签无效');
        }
        return true;
    }

    /** Mirror the platform or tenant's “加入 API 调用” switches. */
    public static function applyApiStatus($query, int $tenantId, $status): void
    {
        if ($status === '' || $status === null) return;
        if (!in_array($status, [0, 1, '0', '1'], true)) throw new \InvalidArgumentException('API 状态无效');
        $ids = \app\common\service\open_platform\OpenStore::settings($tenantId)['models'];
        if ((int)$status === 1) {
            if (!$ids) $query->whereRaw('1 = 0');
            else $query->whereIn('id', $ids);
        } elseif ($ids) {
            $query->whereNotIn('id', $ids);
        }
    }

    public static function active(array $filters): bool
    {
        return ($filters['channel_source'] ?? '') !== '' || !empty($filters['marketing_tags']);
    }

    public static function matches(array $product, array $filters): bool
    {
        $metadata = MarketModelSource::product($product);
        $channel = $filters['channel_source'] ?? '';
        if ($channel !== '' && $channel !== $metadata['channel_source']) return false;
        $tags = $filters['marketing_tags'] ?? [];
        if (!is_array($tags)) return false;
        foreach ($tags as $tag) {
            if (!in_array($tag, $metadata['marketing_tags'], true)) return false;
        }
        return true;
    }

    public static function products(array $products, array $filters): array
    {
        if (!self::active($filters)) return $products;
        return array_values(array_filter($products, static fn(array $row): bool => self::matches($row, $filters)));
    }
}
