<?php
declare(strict_types=1);

namespace app\common\service\power;

/** Project a catalogue or estimate after internal services have used the full quote. */
final class MarketCustomerPricing
{
    private const INTERNAL = [
        'supply_markup', 'standard_price', 'platform_sale_points', 'configured_sale_points',
        'price_rule', 'tenant_price_rule', 'pricing_rules', 'gross_margin_points',
        'market_snapshot', 'price_snapshot', 'pricing_snapshot', 'image_item_pricing',
        'source_payload', 'token_pricing', 'tenant_unit_points',
    ];

    public static function project(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::INTERNAL, true) || (is_string($key) &&
                preg_match('/^(?:(?:platform|upstream)_.*(?:price|cost|margin)|(?:actual_|reserved_|estimated_)?tenant_.*(?:cost|margin))/', $key))) {
                unset($data[$key]);
                continue;
            }
            $wasObject = $value instanceof \stdClass;
            if ($wasObject) $value = (array)$value;
            if ($key === 'token_rates' && is_array($value) && isset($value['sale'])) {
                $value = array_intersect_key($value, array_flip(['sale', 'valid', 'usage_unit_size']));
            }
            if ($key === 'image_billing_items' && is_array($value)) {
                foreach ($value['items'] ?? [] as $i => $item) {
                    $value['items'][$i] = array_diff_key((array)$item, ['cost'=>true, 'manual'=>true]);
                }
            }
            if (is_array($value)) {
                $projected = self::project($value);
                $data[$key] = $wasObject ? (object)$projected : $projected;
            }
        }
        return $data;
    }
}
