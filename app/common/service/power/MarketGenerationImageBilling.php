<?php
namespace app\common\service\power;

/** Snapshot-only image amounts, shared by model and application lifecycles. */
final class MarketGenerationImageBilling
{
    public static function price(float $unitPrice, int $quantity, array $snapshot): float
    {
        // Nano application snapshots historically charged each returned image,
        // without dividing by usage_unit_size. Never reprice those old tasks.
        if (($snapshot['app_code'] ?? '') === 'nano_banana' && !isset($snapshot['billing_meter'])) {
            return round(max(0, $unitPrice) * max(0, $quantity), 6);
        }
        return MarketGenerationMeter::price($unitPrice, $quantity, $snapshot);
    }

    public static function amounts(array $snapshot, int $quantity, float $billedQuantity = 0, bool $deferredUsage = false): array
    {
        $price = static fn(float $unit) => $deferredUsage
            ? MarketUsageSettlementService::price($unit, $billedQuantity, $snapshot)
            : self::price($unit, $quantity, $snapshot);
        return [$price((float)($snapshot['platform_price'] ?? 0)),
            array_key_exists('fixed_user_unit_price', $snapshot)
                ? round(max(0, (float)$snapshot['fixed_user_unit_price']) * $quantity, 6)
                : $price((float)($snapshot['tenant_price'] ?? 0))];
    }
}
