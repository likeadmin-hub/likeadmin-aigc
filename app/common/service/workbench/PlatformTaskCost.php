<?php
declare(strict_types=1);

namespace app\common\service\workbench;

use app\common\service\power\MarketGenerationImageBilling;
use app\common\service\power\MarketGenerationMeter;
use app\common\service\power\MarketUsageSettlementService;

/** Reconstruct supplier points only from the settled row's immutable evidence. */
final class PlatformTaskCost
{
    public static function points(array $row): ?float
    {
        $price = json_decode((string)$row['price_snapshot'], true) ?? [];
        $usage = json_decode((string)$row['usage_snapshot'], true) ?? [];
        if (!is_array($price) || !is_array($usage)) return null;
        return self::fromSnapshot($price, $usage, $row['quantity'] ?? null);
    }

    private static function fromSnapshot(array $price, array $usage, $quantity): ?float
    {
        if (isset($price['rules']) && ($price['billing_owner'] ?? '') === 'gateway') {
            return self::gateway($price, $usage);
        }
        if (isset($price['input'], $price['output'])) {
            $in = $price['input'];
            $out = $price['output'];
            $prompt = self::number($usage['prompt_tokens'] ?? null);
            $completion = self::number($usage['completion_tokens'] ?? null);
            $cached = self::number($usage['cached_input_tokens'] ?? 0);
            $inputRate = self::number($in['pricing_rules']['upstream_rates']['input'] ?? $in['upstream_price'] ?? null);
            $outputRate = self::number($out['pricing_rules']['upstream_rates']['output'] ?? $out['upstream_price'] ?? null);
            $cacheRate = self::number($in['pricing_rules']['upstream_rates']['cached_input'] ?? (isset($in['cached_platform_price']) ? null : $inputRate));
            if (!is_array($in) || !is_array($out) || $prompt === null || $completion === null || $cached === null
                || $cached > $prompt || ($prompt > $cached && $inputRate === null)
                || ($completion > 0 && $outputRate === null) || ($cached > 0 && $cacheRate === null)) return null;
            return round((($prompt-$cached)*$inputRate + $cached*$cacheRate)/MarketUsageSettlementService::unitSize($in)
                + $completion*$outputRate/MarketUsageSettlementService::unitSize($out), 6);
        }
        if (isset($price['token_pricing'])) {
            $pricing = $price['token_pricing'];
            $size = self::number($pricing['usage_unit_size'] ?? null);
            if (!$size) return null;
            $sum = 0.;
            foreach (['input', 'cached_input', 'output'] as $side) {
                $rate = self::number($pricing['upstream'][$side] ?? null);
                $units = self::number($usage['token_breakdown'][$side] ?? null);
                if ($rate === null || $units === null) return null;
                $sum += $rate*$units;
            }
            return round($sum/$size, 6);
        }
        if (isset($price['image_item_pricing'])) {
            $items = $price['image_item_pricing'];
            if (($items['mode'] ?? '') === 'token_items') {
                // Stored at settlement only after validating the provider's itemized receipt.
                return self::number($price['settled_supplier_points'] ?? null);
            }
            $details = $usage['billing_details']['items'] ?? [];
            if (($items['mode'] ?? '') !== 'image_count' || !$details || !is_array($details)) return null;
            $rates = $items['items'] ?? [];
            $sum = 0.;
            foreach ($details as $line) {
                $code = $line['code'] ?? '';
                $rate = self::number($rates[$code]['rate'] ?? null);
                $qty = self::number($line['quantity'] ?? null);
                if ($rate === null || $qty === null) return null;
                $sum += $qty*$rate;
                unset($rates[$code]);
            }
            return $rates ? null : round($sum, 6);
        }
        if (($price['pricing_source'] ?? '') === 'power_market') {
            $rate = self::number($price['upstream_unit_cost'] ?? null);
            $qty = self::number($usage['actual_quantity'] ?? null);
            return $rate !== null && $qty !== null ? round($rate*$qty, 6) : null;
        }
        $quantity = self::number($quantity);
        $rate = self::number($price['upstream_price'] ?? $price['pricing_rules']['platform']['base'] ?? null);
        if ($rate === null || $quantity === null) return null;
        try {
            if (isset($usage['audio_count']) && ($price['usage_unit'] ?? '') === 'per_call') return round($rate*$quantity, 6);
            if (($price['app_code'] ?? '') === 'nano_banana' && empty($price['billing_meter'])) {
                return MarketGenerationImageBilling::price($rate, (int)$quantity, $price);
            }
            return MarketGenerationMeter::price($rate, $quantity, $price);
        } catch (\InvalidArgumentException | \ArithmeticError | \TypeError $e) {
            return null;
        }
    }

    private static function gateway(array $price, array $usage): ?float
    {
        $rules = $price['rules'];
        if (!is_array($rules) || !$rules || count($rules) !== count($usage)) return null;
        $sum = 0.;
        foreach ($rules as $i => $rule) {
            $line = $usage[$i] ?? [];
            if (!isset($rule['sku_id'], $line['sku_id']) || $line['sku_id'] != $rule['sku_id']) return null;
            $basis = $rule['basis'] ?? '';
            $units = self::number($line['units'] ?? null);
            if ($units === null) return null;
            if (isset($price['native_media']['market_snapshot'])) {
                if (count($rules) !== 1) return null;
                return self::fromSnapshot($price['native_media']['market_snapshot'], $line, $units);
            }
            if ($basis === 'file_qa_tokens') {
                $cost = self::fromSnapshot($rule, $line, $units);
            } elseif (in_array($basis, ['input', 'usage', 'character_input'], true)) {
                $rate = self::number($rule['pricing_rules']['platform']['base'] ?? null);
                $size = self::number($rule['unit_size'] ?? null);
                $cost = $size && $rate !== null ? $units*$rate/$size : null;
            } else {
                return null;
            }
            if ($cost === null) return null;
            $sum += $cost;
        }
        return round($sum, 6);
    }

    private static function number($value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 ? (float)$value : null;
    }
}
